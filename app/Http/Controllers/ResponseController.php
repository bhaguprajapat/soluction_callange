<?php

namespace App\Http\Controllers;

use App\Models\Emergency;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\TwilioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Twilio\TwiML\VoiceResponse;

class ResponseController extends Controller
{
    public function __construct(
        private readonly GeminiService $geminiService,
        private readonly TwilioService $twilioService
    ) {
    }

    public function voiceWebhook(Request $request)
    {
        $emergency = $this->resolveEmergency($request);
        $responder = $this->resolveResponder($request);
        $toPhone = $this->normalizePhone((string) $request->input('To', $responder?->phone ?? ''));
        $language = $this->resolveCallLanguage($toPhone);

        Log::info('Twilio voice webhook hit', [
            'emergency_id' => $emergency?->id,
            'responder_id' => $responder?->id,
            'to' => $toPhone,
            'from' => $request->input('From'),
            'call_sid' => $request->input('CallSid'),
            'language' => $language,
        ]);

        $voice = new VoiceResponse();

        if (! $emergency) {
            $voice->say('Is samay mere paas itni hi jaankari uplabdh hai', ['voice' => 'alice']);
            $voice->say('Dhanyavaad, kripya turant action lein', ['voice' => 'alice']);
            $voice->hangup();

            return $this->asTwiml($voice);
        }

        $context = $this->emergencyContext($emergency);
        $intro = $this->geminiService->generateEmergencyOperatorOpening($context, $language);

        $callSid = (string) $request->input('CallSid', '');
        $this->storeConversation($callSid, []);

        $voice->say($intro, ['voice' => 'alice']);

        $gather = $voice->gather([
            'input' => 'speech',
            'speechTimeout' => 'auto',
            'timeout' => 6,
            'action' => $this->buildHandleResponseUrl(
                $request,
                $emergency->id,
                $responder?->id ?? 0,
                $language,
                1
            ),
            'method' => 'POST',
        ]);

        $gather->say($this->nextQuestionPrompt($language), ['voice' => 'alice']);

        $voice->say('Is samay mere paas itni hi jaankari uplabdh hai', ['voice' => 'alice']);
        $voice->say($this->closingLine($language), ['voice' => 'alice']);
        $voice->hangup();

        return $this->asTwiml($voice);
    }

    public function handleVoice(Request $request)
    {
        $language = strtolower((string) $request->input('lang', 'hi')) === 'hi' ? 'hi' : 'en';
        $round = max(1, (int) $request->input('round', 1));
        $speech = trim((string) $request->input('SpeechResult', ''));
        $callSid = (string) $request->input('CallSid', '');

        $emergency = $this->resolveEmergency($request);
        $responder = $this->resolveResponder($request);

        Log::info('Twilio speech callback', [
            'lang' => $language,
            'round' => $round,
            'speech' => $speech,
            'emergency_id' => $emergency?->id,
            'responder_id' => $responder?->id,
            'call_sid' => $callSid,
        ]);

        $voice = new VoiceResponse();

        if (! $emergency) {
            $voice->say('Is samay mere paas itni hi jaankari uplabdh hai', ['voice' => 'alice']);
            $voice->say($this->closingLine($language), ['voice' => 'alice']);
            $voice->hangup();

            return $this->asTwiml($voice);
        }

        if ($speech === '') {
            $voice->say('Is samay mere paas itni hi jaankari uplabdh hai', ['voice' => 'alice']);
            $voice->say($this->closingLine($language), ['voice' => 'alice']);
            $voice->hangup();

            return $this->asTwiml($voice);
        }

        $context = $this->emergencyContext($emergency);
        $history = $this->loadConversation($callSid);

        $answer = $this->geminiService->generateEmergencyOperatorAnswer(
            $context,
            $speech,
            $language,
            $history
        );

        $this->storeConversation(
            $callSid,
            array_slice(array_merge($history, [[
                'q' => $speech,
                'a' => $answer,
            ]]), -6)
        );

        $voice->say($answer, ['voice' => 'alice']);

        if ($round >= 3) {
            $voice->say($this->closingLine($language), ['voice' => 'alice']);
            $voice->hangup();

            return $this->asTwiml($voice);
        }

        $gather = $voice->gather([
            'input' => 'speech',
            'speechTimeout' => 'auto',
            'timeout' => 6,
            'action' => $this->buildHandleResponseUrl(
                $request,
                $emergency->id,
                $responder?->id ?? 0,
                $language,
                $round + 1
            ),
            'method' => 'POST',
        ]);

        $gather->say($this->nextQuestionPrompt($language), ['voice' => 'alice']);

        $voice->say('Is samay mere paas itni hi jaankari uplabdh hai', ['voice' => 'alice']);
        $voice->say($this->closingLine($language), ['voice' => 'alice']);
        $voice->hangup();

        return $this->asTwiml($voice);
    }

    public function testCall(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'regex:/^\+[1-9]\d{7,14}$/'],
            'emergency_id' => ['nullable', 'integer', 'exists:emergencies,id'],
            'responder_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $emergency = isset($validated['emergency_id'])
            ? Emergency::query()->find($validated['emergency_id'])
            : Emergency::query()->latest()->first();

        if (! $emergency) {
            return response()->json([
                'success' => false,
                'error' => 'No emergency record found for test call.',
            ], 422);
        }

        $responderId = $validated['responder_id'] ?? ($request->user()?->id ?? 0);
        $result = $this->twilioService->placeInteractiveCall($validated['to'], $emergency->id, (int) $responderId);

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => ($result['success'] ?? false) ? 'Test call initiated.' : 'Test call failed.',
            'data' => $result,
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function voiceIntro(Request $request)
    {
        return $this->voiceWebhook($request);
    }

    public function handleResponse(Request $request)
    {
        return $this->handleVoice($request);
    }

    private function resolveEmergency(Request $request): ?Emergency
    {
        $id = (int) $request->input('emergency_id', 0);
        if ($id > 0) {
            return Emergency::query()->with('user')->find($id);
        }

        return Emergency::query()->with('user')->latest()->first();
    }

    private function resolveResponder(Request $request): ?User
    {
        $id = (int) $request->input('responder_id', 0);
        if ($id > 0) {
            return User::query()->find($id);
        }

        $to = $this->normalizePhone((string) $request->input('To', ''));
        if ($to === '') {
            return null;
        }

        return User::query()->where('phone', $to)->first();
    }

    private function emergencyContext(Emergency $emergency): array
    {
        return [
            'emergency_id' => $emergency->id,
            'emergency_type' => (string) $emergency->type,
            'severity' => (string) $emergency->severity,
            'location_name' => (string) data_get(
                $emergency->ai_response,
                'location',
                $emergency->location_label ?: sprintf('%.5f, %.5f', $emergency->latitude, $emergency->longitude)
            ),
            'area_road' => (string) data_get(
                $emergency->ai_response,
                'area_road',
                data_get($emergency->ai_response, 'area', data_get($emergency->ai_response, 'road', ''))
            ),
            'pincode' => data_get($emergency->ai_response, 'pincode'),
            'nearest_police_station' => data_get($emergency->ai_response, 'nearest_police_station'),
        ];
    }

    private function resolveCallLanguage(string $phone): string
    {
        return str_starts_with($phone, '+91') ? 'hi' : 'en';
    }

    private function buildHandleResponseUrl(
        Request $request,
        int $emergencyId,
        int $responderId,
        string $language,
        int $round
    ): string {
        return $this->callbackBaseUrl($request).'/twilio/handle?'.http_build_query([
            'emergency_id' => $emergencyId,
            'responder_id' => $responderId,
            'lang' => $language,
            'round' => $round,
        ]);
    }

    private function callbackBaseUrl(Request $request): string
    {
        $configured = rtrim((string) config('services.twilio.voice_webhook_url'), '/');
        if ($this->isPublicHttpUrl($configured)) {
            return $configured;
        }

        $requestHost = rtrim($request->getSchemeAndHttpHost(), '/');

        return $requestHost;
    }

    private function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        return ! in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function nextQuestionPrompt(string $language): string
    {
        if ($language === 'hi') {
            return 'Kripya apna agla sawaal boliye.';
        }

        return 'Please ask your next question.';
    }

    private function closingLine(string $language): string
    {
        if ($language === 'hi') {
            return 'Dhanyavaad, kripya turant action lein';
        }

        return 'Thank you, please take immediate action.';
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\s+/', '', $phone) ?: '';
    }

    private function cacheKey(string $callSid): string
    {
        return 'twilio:voice:conversation:'.($callSid !== '' ? $callSid : 'unknown');
    }

    private function loadConversation(string $callSid): array
    {
        if ($callSid === '') {
            return [];
        }

        $data = Cache::get($this->cacheKey($callSid), []);

        return is_array($data) ? $data : [];
    }

    private function storeConversation(string $callSid, array $conversation): void
    {
        if ($callSid === '') {
            return;
        }

        Cache::put($this->cacheKey($callSid), $conversation, now()->addMinutes(20));
    }

    private function asTwiml(VoiceResponse $voice)
    {
        return response((string) $voice, 200, ['Content-Type' => 'text/xml']);
    }
}
