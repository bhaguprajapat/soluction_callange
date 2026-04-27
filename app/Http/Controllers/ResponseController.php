<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Emergency;
use App\Models\ResponderAction;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\TwilioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $attempt = max(0, (int) $request->input('attempt', 0));

        $emergency = $this->resolveEmergency($request);
        $responder = $this->resolveResponder($request);

        Log::info('Twilio voice webhook hit', [
            'emergency_id' => $emergency?->id,
            'responder_id' => $responder?->id,
            'attempt' => $attempt,
            'to' => $request->input('To'),
            'from' => $request->input('From'),
            'call_sid' => $request->input('CallSid'),
        ]);

        $voice = new VoiceResponse();

        if (! $emergency) {
            $voice->say('Emergency call center is active. Press 1 for Hindi or 2 for English.');
            $voice->hangup();

            return $this->asTwiml($voice);
        }

        $gather = $voice->gather([
            'numDigits' => 1,
            'action' => $this->buildHandleResponseUrl(
                $request,
                $emergency->id,
                $responder?->id ?? 0,
                'language',
                'en',
                0
            ),
            'method' => 'POST',
            'timeout' => 7,
        ]);

        $gather->say('Emergency alert received. Press 1 for Hindi. Press 2 for English.', ['voice' => 'alice']);

        if ($attempt < 1) {
            $voice->say('No input received. Trying once more.');
            $voice->redirect($this->buildVoiceIntroUrl($request, $emergency->id, $responder?->id ?? 0, $attempt + 1), [
                'method' => 'POST',
            ]);
        } else {
            $voice->say('No input received. Goodbye.');
            $voice->hangup();
        }

        return $this->asTwiml($voice);
    }

    public function handleVoice(Request $request)
    {
        $phase = (string) $request->input('phase', 'language');
        $language = (string) $request->input('lang', 'en');
        $round = max(0, (int) $request->input('round', 0));
        $digit = (string) $request->input('Digits', '');
        $speech = trim((string) $request->input('SpeechResult', ''));

        $emergency = $this->resolveEmergency($request);
        $responder = $this->resolveResponder($request);

        Log::info('Twilio handle callback', [
            'phase' => $phase,
            'digit' => $digit,
            'speech' => $speech,
            'lang' => $language,
            'round' => $round,
            'emergency_id' => $emergency?->id,
            'responder_id' => $responder?->id,
            'call_sid' => $request->input('CallSid'),
        ]);

        $voice = new VoiceResponse();
        if (! $emergency) {
            $voice->say('Emergency context unavailable. Goodbye.');
            $voice->hangup();

            return $this->asTwiml($voice);
        }

        if ($phase === 'language') {
            $language = $digit === '1' ? 'hi' : 'en';

            $context = $this->emergencyContext($emergency);
            $intro = $this->geminiService->generateResponderIvrMessage(
                $emergency->type,
                $emergency->severity,
                $context['location_name'],
                $context['maps_link'],
                $language
            );

            $voice->say($intro, ['voice' => 'alice']);
            $gather = $voice->gather([
                'input' => 'speech dtmf',
                'numDigits' => 1,
                'speechTimeout' => 'auto',
                'action' => $this->buildHandleResponseUrl(
                    $request,
                    $emergency->id,
                    $responder?->id ?? 0,
                    'qa',
                    $language,
                    0
                ),
                'method' => 'POST',
                'timeout' => 7,
            ]);

            if ($language === 'hi') {
                $gather->say('Sawaal poochiye ya keypad ka upyog kijiye. Response dene ke liye 9 dabaiye.');
            } else {
                $gather->say('Ask your question or use keypad. Press 9 if you are responding.');
            }
            $voice->say($language === 'hi' ? 'Koi input nahin mila. Dhanyavaad.' : 'No input received. Thank you.');
            $voice->hangup();

            return $this->asTwiml($voice);
        }

        if ($phase === 'qa') {
            if ($digit === '9' && $responder) {
                $this->markResponderAccepted($emergency, $responder);

                $voice->say(
                    $language === 'hi'
                        ? 'Dhanyavaad. Aapko responding mark kar diya gaya hai.'
                        : 'Thank you. You are marked as responding.'
                );
                $voice->hangup();

                return $this->asTwiml($voice);
            }

            $question = $speech !== '' ? $speech : $this->mapDigitToQuestion($digit, $language);
            if ($question === '') {
                $question = $language === 'hi'
                    ? 'Emergency ki current sthiti kya hai?'
                    : 'What is the current emergency status?';
            }

            $context = $this->emergencyContext($emergency);
            $answer = $this->geminiService->answerEmergencyQuery(
                $question,
                $emergency->type,
                $emergency->severity,
                $context['location_name'],
                $context['pincode'],
                $context['nearest_police_station'],
                $context['maps_link'],
                $language
            );

            $voice->say($answer, ['voice' => 'alice']);

            if ($round < 1) {
                $gather = $voice->gather([
                    'input' => 'speech dtmf',
                    'numDigits' => 1,
                    'speechTimeout' => 'auto',
                    'action' => $this->buildHandleResponseUrl(
                        $request,
                        $emergency->id,
                        $responder?->id ?? 0,
                        'qa',
                        $language,
                        $round + 1
                    ),
                    'method' => 'POST',
                    'timeout' => 7,
                ]);
                $gather->say(
                    $language === 'hi'
                        ? 'Agar aur jaankari chahiye to sawaal poochiye. Respond karne ke liye 9 dabaiye.'
                        : 'If you need more details, ask now. Press 9 to confirm response.'
                );
                $voice->say($language === 'hi' ? 'Koi input nahin mila. Call samaapt.' : 'No input received. Ending the call.');
                $voice->hangup();

                return $this->asTwiml($voice);
            }

            $voice->say($language === 'hi' ? 'Dhanyavaad. Call samaapt.' : 'Thank you. Ending the call.');
            $voice->hangup();

            return $this->asTwiml($voice);
        }

        $voice->say('Invalid IVR state. Goodbye.');
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
            'location_name' => (string) data_get(
                $emergency->ai_response,
                'location',
                $emergency->location_label ?: sprintf('%.5f, %.5f', $emergency->latitude, $emergency->longitude)
            ),
            'maps_link' => (string) data_get(
                $emergency->ai_response,
                'maps_link',
                sprintf('https://www.google.com/maps?q=%s,%s', $emergency->latitude, $emergency->longitude)
            ),
            'pincode' => data_get($emergency->ai_response, 'pincode'),
            'nearest_police_station' => data_get($emergency->ai_response, 'nearest_police_station'),
        ];
    }

    private function markResponderAccepted(Emergency $emergency, User $responder): void
    {
        $action = ResponderAction::query()->firstOrNew([
            'emergency_id' => $emergency->id,
            'responder_id' => $responder->id,
        ]);
        $alreadyAccepted = $action->exists && $action->status === 'accepted';

        $action->status = 'accepted';
        if (! $action->exists) {
            $action->created_at = now();
        }
        $action->save();

        if ($alreadyAccepted) {
            return;
        }

        $acceptedCount = ResponderAction::query()
            ->where('emergency_id', $emergency->id)
            ->where('status', 'accepted')
            ->count();

        $message = $acceptedCount === 1
            ? 'Help is on the way: '.ucfirst((string) $responder->role).' is responding to your emergency.'
            : "Help is on the way: {$acceptedCount} responders are on the way.";

        if (! empty($emergency->user?->phone)) {
            $status = $this->twilioService->sendSms($emergency->user->phone, $message);
            Alert::create([
                'emergency_id' => $emergency->id,
                'user_id' => $emergency->user->id,
                'alert_type' => 'sms',
                'status' => $status ? 'sent' : 'failed',
            ]);
        }
    }

    private function buildVoiceIntroUrl(Request $request, int $emergencyId, int $responderId, int $attempt): string
    {
        return $this->callbackBaseUrl($request).'/twilio/voice?'.http_build_query([
            'emergency_id' => $emergencyId,
            'responder_id' => $responderId,
            'attempt' => $attempt,
        ]);
    }

    private function buildHandleResponseUrl(
        Request $request,
        int $emergencyId,
        int $responderId,
        string $phase,
        string $language,
        int $round
    ): string {
        return $this->callbackBaseUrl($request).'/twilio/handle?'.http_build_query([
            'emergency_id' => $emergencyId,
            'responder_id' => $responderId,
            'phase' => $phase,
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

        if ($configured !== '') {
            Log::warning('Ignoring non-public Twilio voice webhook base URL', [
                'configured' => $configured,
            ]);
        }

        $requestHost = rtrim($request->getSchemeAndHttpHost(), '/');
        if ($this->isPublicHttpUrl($requestHost)) {
            return $requestHost;
        }

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

    private function mapDigitToQuestion(string $digit, string $language): string
    {
        if ($digit === '') {
            return '';
        }

        return match ($digit) {
            '1' => $language === 'hi'
                ? 'Yahaan kya hua hai?'
                : 'What happened at the location?',
            '2' => $language === 'hi'
                ? 'Exact location aur pincode kya hai?'
                : 'What is the exact location and pincode?',
            '3' => $language === 'hi'
                ? 'Nearest police station kaunsa hai?'
                : 'Which is the nearest police station?',
            default => '',
        };
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone) ?: '';

        return $phone;
    }

    private function asTwiml(VoiceResponse $voice)
    {
        return response((string) $voice, 200, ['Content-Type' => 'text/xml']);
    }
}
