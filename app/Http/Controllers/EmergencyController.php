<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Emergency;
use App\Services\AlertService;
use App\Services\GeminiService;
use App\Services\LocationIntelligenceService;
use App\Services\TwilioService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmergencyController extends Controller
{
    public function __construct(
        private readonly GeminiService $geminiService,
        private readonly AlertService $alertService,
        private readonly LocationIntelligenceService $locationIntelligenceService,
        private readonly TwilioService $twilioService
    ) {
    }

    public function trigger(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedPhone()) {
            return response()->json([
                'message' => 'Phone verification is required before triggering emergencies.',
            ], 403);
        }

        $validated = $request->validate([
            'type' => ['required', 'in:fire,attack,medical,kidnap,other'],
            'location_mode' => ['nullable', 'in:current,custom'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_label' => ['nullable', 'string', 'max:255'],
        ]);

        $duplicate = Emergency::query()
            ->where('user_id', $request->user()->id)
            ->where('type', $validated['type'])
            ->where('status', 'active')
            ->where('created_at', '>=', now()->subSeconds(30))
            ->whereRaw('ABS(latitude - ?) <= 0.0003', [$validated['latitude']])
            ->whereRaw('ABS(longitude - ?) <= 0.0003', [$validated['longitude']])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'Duplicate emergency blocked. Please wait a few seconds before retrying.',
            ], 429);
        }

        $locationText = $validated['location_label']
            ?? sprintf('%.5f, %.5f', $validated['latitude'], $validated['longitude']);
        $locationIntel = $this->locationIntelligenceService->resolve(
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            $locationText
        );
        $mapsLink = (string) ($locationIntel['maps_link'] ?? sprintf(
            'https://www.google.com/maps?q=%s,%s',
            $validated['latitude'],
            $validated['longitude']
        ));
        $locationText = (string) ($locationIntel['location_name'] ?? $locationText);

        $analysis = $this->geminiService->analyzeEmergency(
            $validated['type'],
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            $locationText,
            $mapsLink,
            $locationIntel
        );

        $emergency = Emergency::create([
            'user_id' => $request->user()->id,
            'type' => $validated['type'],
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'location_label' => $validated['location_label'] ?? null,
            'severity' => $analysis['severity'],
            'ai_response' => $analysis,
            'status' => 'active',
        ]);

        $clusterActive = $this->detectCriticalCluster($emergency);
        if ($clusterActive) {
            $analysis['severity'] = 'critical';
            $analysis['message'] = 'CRITICAL CLUSTER EVENT: '.$analysis['message'];
            $analysis['cluster_event'] = true;
        }

        $analysis['message'] = $this->composeLocationMessage($analysis['message'], $mapsLink);
        $analysis['maps_link'] = $mapsLink;
        $analysis['location'] = $locationText;
        $analysis['pincode'] = $locationIntel['pincode'] ?? null;
        $analysis['nearest_police_station'] = $locationIntel['nearest_police_station'] ?? null;

        $voiceMessage = $this->geminiService->generateVoiceAlertMessage($validated['type'], $locationText);
        $analysis['voice_message'] = $voiceMessage;

        $emergency->update([
            'severity' => $analysis['severity'],
            'ai_response' => $analysis,
        ]);

        $this->alertService->dispatchAlerts(
            $emergency,
            $analysis['responders_needed'],
            $analysis['message'],
            $voiceMessage,
            $clusterActive
        );
        $this->notifyComplainant($request, $emergency);

        return response()->json([
            'message' => 'Emergency triggered successfully.',
            'data' => [
                'emergency_id' => $emergency->id,
                'severity' => $emergency->severity,
                'ai_response' => $analysis,
                'voice_message' => $voiceMessage,
                'cluster_event' => $clusterActive,
            ],
        ]);
    }

    private function detectCriticalCluster(Emergency $emergency): bool
    {
        $windowStart = Carbon::now()->subMinutes(5);

        $count = Emergency::query()
            ->where('created_at', '>=', $windowStart)
            ->whereRaw('ABS(latitude - ?) <= 0.0010', [$emergency->latitude])
            ->whereRaw('ABS(longitude - ?) <= 0.0010', [$emergency->longitude])
            ->count();

        return $count > 3;
    }

    private function composeLocationMessage(string $message, string $mapsLink): string
    {
        return trim($message)."\n\nLocation:\n{$mapsLink}\nImmediate response required.";
    }

    private function notifyComplainant(Request $request, Emergency $emergency): void
    {
        $user = $request->user();
        if (! $user || empty($user->phone)) {
            return;
        }

        $status = $this->twilioService->sendSms(
            $user->phone,
            'Your emergency has been reported successfully. Help is on the way.'
        );

        try {
            $callResult = $this->twilioService->makeCall(
                $user->phone,
                'Emergency alert received. Help is being dispatched immediately.'
            );

            Log::info('Emergency complainant voice call result', [
                'emergency_id' => $emergency->id,
                'user_id' => $user->id,
                'success' => (bool) ($callResult['success'] ?? false),
                'sid' => $callResult['sid'] ?? null,
                'error' => $callResult['error'] ?? null,
            ]);

            if (! ($callResult['success'] ?? false)) {
                Log::warning('Emergency complainant voice call failed but flow continues', [
                    'emergency_id' => $emergency->id,
                    'user_id' => $user->id,
                    'error' => $callResult['error'] ?? 'Unknown Twilio voice error',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Emergency complainant voice call exception (flow continues)', [
                'emergency_id' => $emergency->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        Alert::create([
            'emergency_id' => $emergency->id,
            'user_id' => $user->id,
            'alert_type' => 'sms',
            'status' => $status ? 'sent' : 'failed',
        ]);
    }
}
