<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    public const EMERGENCY_OPERATOR_SYSTEM_PROMPT = <<<'PROMPT'
You are an emergency voice operator for live responder calls.
You must follow these non-negotiable rules:
1) Use only provided backend emergency context. Never invent facts.
2) Never reveal personal user data (name, phone, identity, contact details).
3) Keep spoken output short, clear, and phone-friendly (max 2 short sentences).
4) If context is missing, say exactly: "Is samay mere paas itni hi jaankari uplabdh hai".
5) If responder asks pincode and pincode exists, repeat it twice.
6) For Indian numbers (+91), respond only in natural spoken Hindi.
7) Stay action-focused and emergency-specific, no generic filler.
8) Never ask caller to press keys.
PROMPT;

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

    public function generateEmergencyOperatorOpening(array $context, string $language = 'hi'): string
    {
        if (! config('services.gemini.api_key')) {
            return $this->fallbackEmergencyOpening($context, $language);
        }

        $language = strtolower($language) === 'hi' ? 'hi' : 'en';
        $fallback = $this->fallbackEmergencyOpening($context, $language);

        $prompt = $this->buildEmergencyOperatorPrompt(
            $context,
            $language,
            [],
            null,
            true
        );

        return $this->generatePlainText($prompt, $fallback);
    }

    public function generateEmergencyOperatorAnswer(
        array $context,
        string $question,
        string $language = 'hi',
        array $conversation = []
    ): string {
        $language = strtolower($language) === 'hi' ? 'hi' : 'en';

        $directAnswer = $this->directEmergencyAnswer($context, $question, $language);
        if ($directAnswer !== null) {
            return $directAnswer;
        }

        if (! config('services.gemini.api_key')) {
            return 'Is samay mere paas itni hi jaankari uplabdh hai';
        }

        $prompt = $this->buildEmergencyOperatorPrompt(
            $context,
            $language,
            $conversation,
            $question,
            false
        );

        $answer = $this->generatePlainText($prompt, 'Is samay mere paas itni hi jaankari uplabdh hai');

        return $this->sanitizeEmergencyVoiceAnswer($answer, $question, $context, $language);
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

    private function buildEmergencyOperatorPrompt(
        array $context,
        string $language,
        array $conversation,
        ?string $question,
        bool $opening
    ): string {
        $conversationText = collect($conversation)
            ->take(-6)
            ->map(function (array $turn): string {
                $q = trim((string) ($turn['q'] ?? ''));
                $a = trim((string) ($turn['a'] ?? ''));

                return "Q: {$q}\nA: {$a}";
            })
            ->implode("\n");

        $instruction = $opening
            ? 'Create opening emergency statement for responder as soon as call connects.'
            : 'Answer responder question based on backend data and recent conversation.';

        $langInstruction = $language === 'hi'
            ? 'Respond only in natural spoken Hindi.'
            : 'Respond only in plain spoken English.';

        return self::EMERGENCY_OPERATOR_SYSTEM_PROMPT."\n\n".
            "Task: {$instruction}\n".
            "{$langInstruction}\n".
            "Emergency context (backend truth source):\n".
            '- emergency_type: '.($context['emergency_type'] ?? 'unknown')."\n".
            '- severity: '.($context['severity'] ?? 'unknown')."\n".
            '- location_name: '.($context['location_name'] ?? 'unknown')."\n".
            '- area_road: '.($context['area_road'] ?? 'unknown')."\n".
            '- pincode: '.($context['pincode'] ?? 'unknown')."\n".
            '- nearest_police_station: '.($context['nearest_police_station'] ?? 'unknown')."\n".
            "Recent call memory:\n".($conversationText !== '' ? $conversationText : 'none')."\n".
            'Responder question: '.($question ?: 'N/A')."\n".
            "Output constraints:\n".
            "- no markdown\n".
            "- no bullet points\n".
            "- max 2 short sentences\n";
    }

    private function fallbackEmergencyOpening(array $context, string $language): string
    {
        $location = trim((string) ($context['location_name'] ?? ''));
        $areaRoad = trim((string) ($context['area_road'] ?? ''));
        $type = (string) ($context['emergency_type'] ?? 'emergency');

        if ($location === '' && $areaRoad === '') {
            return 'Is samay mere paas itni hi jaankari uplabdh hai';
        }

        $locationLine = $this->buildLocationPhrase($location, $areaRoad);

        if ($language === 'hi') {
            return ucfirst($type).' emergency '.$locationLine.' report hui hai. Kripya turant response dein.';
        }

        return ucfirst($type).' emergency reported at '.$locationLine.'. Please respond immediately.';
    }

    private function directEmergencyAnswer(array $context, string $question, string $language): ?string
    {
        $q = strtolower(trim($question));
        if ($q === '') {
            return null;
        }

        $location = trim((string) ($context['location_name'] ?? ''));
        $areaRoad = trim((string) ($context['area_road'] ?? ''));
        $pincode = trim((string) ($context['pincode'] ?? ''));

        $isPincodeQuestion = (bool) preg_match('/\b(pin|pincode|postal|zip)\b/i', $q);
        if ($isPincodeQuestion) {
            if ($pincode === '') {
                return 'Is samay mere paas itni hi jaankari uplabdh hai';
            }

            if ($language === 'hi') {
                return "{$pincode}, repeat karta hoon {$pincode}";
            }

            return "Pincode is {$pincode}. I repeat, {$pincode}.";
        }

        $isLocationQuestion = (bool) preg_match('/\b(where|location|address|road|area)\b/i', $q);
        if ($isLocationQuestion) {
            $locationLine = $this->buildLocationPhrase($location, $areaRoad);
            if ($locationLine === '') {
                return 'Is samay mere paas itni hi jaankari uplabdh hai';
            }

            if ($language === 'hi') {
                return $locationLine;
            }

            return "Location is {$locationLine}.";
        }

        return null;
    }

    private function sanitizeEmergencyVoiceAnswer(string $answer, string $question, array $context, string $language): string
    {
        $answer = trim(preg_replace('/\s+/', ' ', $answer) ?: $answer);
        if ($answer === '') {
            return 'Is samay mere paas itni hi jaankari uplabdh hai';
        }

        // Hard privacy guard for obvious personal-data leaks.
        if (preg_match('/\b\d{10,}\b|@|name\s*:/i', $answer)) {
            return 'Is samay mere paas itni hi jaankari uplabdh hai';
        }

        $q = strtolower($question);
        $isPincodeQuestion = (bool) preg_match('/\b(pin|pincode|postal|zip)\b/i', $q);
        if ($isPincodeQuestion) {
            $pincode = trim((string) ($context['pincode'] ?? ''));
            if ($pincode === '') {
                return 'Is samay mere paas itni hi jaankari uplabdh hai';
            }

            $count = preg_match_all('/'.preg_quote($pincode, '/').'/', $answer);
            if ($count < 2) {
                return $language === 'hi'
                    ? "{$pincode}, repeat karta hoon {$pincode}"
                    : "Pincode is {$pincode}. I repeat, {$pincode}.";
            }
        }

        return $answer;
    }

    private function buildLocationPhrase(string $location, string $areaRoad): string
    {
        $location = trim($location);
        $areaRoad = trim($areaRoad);

        if ($location !== '' && $areaRoad !== '') {
            return "{$location}, {$areaRoad}";
        }

        return $location !== '' ? $location : $areaRoad;
    }
}
