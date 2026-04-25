<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Emergency;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AlertService
{
    public function __construct(private readonly TwilioService $twilioService)
    {
    }

    public function dispatchAlerts(
        Emergency $emergency,
        array $responders,
        string $message,
        string $voiceMessage,
        bool $broadcast = false
    ): void
    {
        $targets = User::query()
            ->whereIn('role', $responders)
            ->get();

        $twilioResults = $this->twilioService->dispatchEmergencyBroadcast($targets, $message, $voiceMessage);

        foreach ($targets as $target) {
            $this->createAlert($emergency, $target, 'dashboard', 'sent');
            $this->sendEmail($emergency, $target, $message);

            $smsStatus = $this->findTwilioStatus($twilioResults['sms'] ?? [], $target->id);
            $waStatus = $this->findTwilioStatus($twilioResults['whatsapp'] ?? [], $target->id);
            $callStatus = $this->findTwilioStatus($twilioResults['call'] ?? [], $target->id);

            $this->createAlert($emergency, $target, 'sms', $smsStatus ? 'sent' : 'failed');
            $this->createAlert($emergency, $target, 'whatsapp', $waStatus ? 'sent' : 'failed');
            $this->createAlert($emergency, $target, 'call', $callStatus ? 'sent' : 'failed');
        }

        if ($broadcast) {
            User::query()
                ->whereIn('role', ['police', 'ambulance', 'fire'])
                ->each(function (User $target) use ($emergency): void {
                    $this->createAlert($emergency, $target, 'broadcast', 'sent');
                });
        }

        Log::info('AI voice message dispatched for emergency', [
            'emergency_id' => $emergency->id,
            'voice_message' => $voiceMessage,
        ]);
    }

    private function sendEmail(Emergency $emergency, User $target, string $message): void
    {
        try {
            Mail::raw(
                "AutoRescue AI Alert\n\nEmergency #{$emergency->id}\nType: {$emergency->type}\nSeverity: {$emergency->severity}\nMessage: {$message}",
                fn ($mail) => $mail->to($target->email)->subject('AutoRescue AI Emergency Alert')
            );

            $this->createAlert($emergency, $target, 'email', 'sent');
        } catch (\Throwable $e) {
            Log::error('Email alert failed', ['user_id' => $target->id, 'error' => $e->getMessage()]);
            $this->createAlert($emergency, $target, 'email', 'failed');
        }
    }

    private function createAlert(Emergency $emergency, User $target, string $type, string $status): void
    {
        Alert::create([
            'emergency_id' => $emergency->id,
            'user_id' => $target->id,
            'alert_type' => $type,
            'status' => $status,
        ]);
    }

    private function findTwilioStatus(array $items, int $userId): bool
    {
        foreach ($items as $item) {
            if (($item['user_id'] ?? 0) === $userId) {
                return (bool) ($item['ok'] ?? false);
            }
        }

        return false;
    }
}
