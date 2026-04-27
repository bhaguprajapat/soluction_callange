<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    public function analyzeEmergency(
        string $type,
        float $latitude,
        float $longitude,
        ?string $locationLabel = null,
        ?string $mapsLink = null,
        array $locationIntel = []
    ): array
    {
        if (! config('services.gemini.api_key')) {
            return $this->fallbackResponse($type, $locationLabel);
        }

        $pincode = $locationIntel['pincode'] ?? null;
        $nearestStation = $locationIntel['nearest_police_station'] ?? null;

        $prompt = <<<PROMPT
Analyze this emergency signal and return JSON only.
Input:
- type: {$type}
- latitude: {$latitude}
- longitude: {$longitude}
- location_label: {$locationLabel}
- maps_link: {$mapsLink}
- pincode: {$pincode}
- nearest_police_station: {$nearestStation}

Output keys (strict):
type, severity, responders_needed, message

Rules:
- severity one of: low, medium, high, critical
- responders_needed is array values from: fire, police, ambulance
- message must sound human, urgent, and specific to this location
- do not include markdown or code fences
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

                return $this->fallbackResponse($type, $locationLabel);
            }

            $text = data_get($response->json(), 'candidates.0.content.parts.0.text', '');
            $parsed = $this->extractJson($text);

            return $this->normalizeResponse($type, $parsed, $locationLabel);
        } catch (\Throwable $e) {
            Log::error('Gemini API exception', ['error' => $e->getMessage()]);

            return $this->fallbackResponse($type, $locationLabel);
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

    public function generateDetailedVoiceMessage(string $type, string $locationText, string $mapsLink): string
    {
        if (! config('services.gemini.api_key')) {
            return ucfirst($type)." emergency at {$locationText}. Responders should proceed immediately. Map link sent by SMS.";
        }

        $prompt = <<<PROMPT
Create a detailed but concise emergency briefing for a voice call responder.
Inputs:
- emergency_type: {$type}
- location_text: {$locationText}
- maps_link: {$mapsLink}
Rules:
- 2 short sentences
- mention potential risk and urgency
- plain text only
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
                return ucfirst($type)." emergency at {$locationText}. Responders should proceed immediately. Map link sent by SMS.";
            }

            $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));

            return $text !== '' ? $text : ucfirst($type)." emergency at {$locationText}. Responders should proceed immediately. Map link sent by SMS.";
        } catch (\Throwable) {
            return ucfirst($type)." emergency at {$locationText}. Responders should proceed immediately. Map link sent by SMS.";
        }
    }

    public function generateResponderIvrMessage(
        string $type,
        string $severity,
        string $locationText,
        string $mapsLink,
        string $language
    ): string {
        if (! config('services.gemini.api_key')) {
            return $this->fallbackIvrIntro($type, $locationText, $language);
        }

        $lang = $language === 'hi' ? 'Hindi' : 'English';
        $prompt = <<<PROMPT
Create a short emergency IVR message in {$lang}.
Inputs:
- type: {$type}
- severity: {$severity}
- location: {$locationText}
- maps: {$mapsLink}
Rules:
- 1 to 2 short sentences
- simple and calm
- do not reveal any personal identity, phone number, or private user data
PROMPT;

        return $this->generatePlainText($prompt, $this->fallbackIvrIntro($type, $locationText, $language));
    }

    public function answerEmergencyQuery(
        string $question,
        string $type,
        string $severity,
        string $locationText,
        ?string $pincode,
        ?string $nearestPoliceStation,
        string $mapsLink,
        string $language
    ): string {
        if (! config('services.gemini.api_key')) {
            return $this->fallbackIvrAnswer($type, $locationText, $mapsLink, $language);
        }

        $lang = $language === 'hi' ? 'Hindi' : 'English';
        $prompt = <<<PROMPT
Answer responder question in {$lang} using emergency context.
Question: {$question}
Emergency:
- type: {$type}
- severity: {$severity}
- location_name: {$locationText}
- pincode: {$pincode}
- nearest_police_station: {$nearestPoliceStation}
- maps_link: {$mapsLink}

Safety:
- Never share personal user data, names, phone numbers, or identity.
- Give only emergency context, location details, and general safety guidance.
- Keep answer under 3 sentences and easy to understand.
PROMPT;

        return $this->generatePlainText($prompt, $this->fallbackIvrAnswer($type, $locationText, $mapsLink, $language));
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

    private function fallbackResponse(string $type, ?string $locationLabel = null): array
    {
        return [
            'type' => $type,
            'severity' => 'high',
            'responders_needed' => $this->defaultRespondersFor($type),
            'message' => ucfirst($type).' emergency detected at '.($locationLabel ?: 'reported location').'. Immediate response recommended.',
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

    private function generatePlainText(string $prompt, string $fallback): string
    {
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
                return $fallback;
            }

            $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));

            return $text !== '' ? $text : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function fallbackIvrIntro(string $type, string $locationText, string $language): string
    {
        if ($language === 'hi') {
            return ucfirst($type)." emergency report hui hai {$locationText} par. Kripya turant response dein.";
        }

        return ucfirst($type)." emergency is reported at {$locationText}. Please respond immediately.";
    }

    private function fallbackIvrAnswer(string $type, string $locationText, string $mapsLink, string $language): string
    {
        if ($language === 'hi') {
            return ucfirst($type)." incident {$locationText} par report hua hai. Gambhir sthiti hai. Map link SMS mein bheja gaya hai: {$mapsLink}.";
        }

        return ucfirst($type)." incident is reported at {$locationText} with urgent severity. Map link has been sent by SMS: {$mapsLink}.";
    }
}
