<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    public function analyzeEmergency(string $type, float $latitude, float $longitude, ?string $locationLabel = null): array
    {
        if (! config('services.gemini.api_key')) {
            return $this->fallbackResponse($type);
        }

        $prompt = <<<PROMPT
Analyze this emergency signal and return JSON only.
Input:
- type: {$type}
- latitude: {$latitude}
- longitude: {$longitude}
- location_label: {$locationLabel}

Output keys (strict):
type, severity, responders_needed, message

Rules:
- severity one of: low, medium, high, critical
- responders_needed is array values from: fire, police, ambulance
PROMPT;

        $endpoint = sprintf(
            '%s/models/%s:generateContent?key=%s',
            rtrim(config('services.gemini.base_url'), '/'),
            config('services.gemini.model'),
            config('services.gemini.api_key')
        );

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->post($endpoint, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Gemini API failed', ['status' => $response->status(), 'body' => $response->body()]);

                return $this->fallbackResponse($type);
            }

            $text = data_get($response->json(), 'candidates.0.content.parts.0.text', '');
            $parsed = $this->extractJson($text);

            return $this->normalizeResponse($type, $parsed, $locationLabel);
        } catch (\Throwable $e) {
            Log::error('Gemini API exception', ['error' => $e->getMessage()]);

            return $this->fallbackResponse($type);
        }
    }

    private function extractJson(string $raw): array
    {
        $clean = trim($raw);
        $clean = preg_replace('/^```json|```$/m', '', $clean) ?: $clean;

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $clean, $matches)) {
            $decoded = json_decode($matches[0], true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    public function generateVoiceAlertMessage(string $type, string $locationText): string
    {
        if (! config('services.gemini.api_key')) {
            return ucfirst($type)." emergency detected at {$locationText}. Immediate assistance required.";
        }

        $prompt = <<<PROMPT
Generate one concise emergency voice-call sentence for responders.
Emergency type: {$type}
Location: {$locationText}
Constraints:
- <= 20 words
- include urgency
- plain English, no JSON
PROMPT;

        $endpoint = sprintf(
            '%s/models/%s:generateContent?key=%s',
            rtrim(config('services.gemini.base_url'), '/'),
            config('services.gemini.model'),
            config('services.gemini.api_key')
        );

        try {
            $response = Http::timeout(15)->acceptJson()->post($endpoint, [
                'contents' => [[
                    'parts' => [['text' => $prompt]],
                ]],
            ]);

            if (! $response->successful()) {
                return ucfirst($type)." emergency detected at {$locationText}. Immediate assistance required.";
            }

            $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));

            return $text !== '' ? $text : ucfirst($type)." emergency detected at {$locationText}. Immediate assistance required.";
        } catch (\Throwable) {
            return ucfirst($type)." emergency detected at {$locationText}. Immediate assistance required.";
        }
    }

    private function normalizeResponse(string $type, array $payload, ?string $locationLabel = null): array
    {
        $severity = strtolower((string) ($payload['severity'] ?? 'high'));
        if (! in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
            $severity = 'high';
        }

        $responders = array_values(array_filter(
            (array) ($payload['responders_needed'] ?? []),
            fn ($item) => in_array($item, ['fire', 'police', 'ambulance'], true)
        ));

        if ($responders === []) {
            $responders = $this->defaultRespondersFor($type);
        }

        return [
            'type' => strtolower((string) ($payload['type'] ?? $type)),
            'severity' => $severity,
            'responders_needed' => $responders,
            'message' => (string) ($payload['message'] ?? ucfirst($type).' emergency detected at '.($locationLabel ?: 'shared coordinates').'. Response team dispatched.'),
        ];
    }

    private function fallbackResponse(string $type): array
    {
        return [
            'type' => $type,
            'severity' => 'high',
            'responders_needed' => $this->defaultRespondersFor($type),
            'message' => strtoupper($type).' emergency detected. Immediate response recommended.',
        ];
    }

    private function defaultRespondersFor(string $type): array
    {
        return match ($type) {
            'fire' => ['fire', 'ambulance'],
            'medical' => ['ambulance', 'police'],
            'attack', 'kidnap' => ['police', 'ambulance'],
            default => ['police', 'ambulance', 'fire'],
        };
    }
}
