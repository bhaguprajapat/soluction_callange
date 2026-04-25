<?php

namespace App\Http\Controllers;

use App\Models\Emergency;
use App\Services\AlertService;
use App\Services\GeminiService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmergencyController extends Controller
{
    public function __construct(
        private readonly GeminiService $geminiService,
        private readonly AlertService $alertService
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

        $analysis = $this->geminiService->analyzeEmergency(
            $validated['type'],
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            $validated['location_label'] ?? null
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
            $emergency->update([
                'severity' => 'critical',
                'ai_response' => $analysis,
            ]);
        }

        $locationText = $validated['location_label']
            ?? sprintf('%.5f, %.5f', $validated['latitude'], $validated['longitude']);
        $voiceMessage = $this->geminiService->generateVoiceAlertMessage($validated['type'], $locationText);

        $this->alertService->dispatchAlerts(
            $emergency,
            $analysis['responders_needed'],
            $analysis['message'],
            $voiceMessage,
            $clusterActive
        );

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
}
