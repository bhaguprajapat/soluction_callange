<?php

namespace App\Services;

use App\Models\Otp;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OTPService
{
    public function __construct(private readonly TwilioService $twilioService)
    {
    }

    public function sendOtp(User $user, string $phone): array
    {
        $this->guardRateLimit($user);

        Otp::query()->where('user_id', $user->id)->delete();

        $otp = (string) random_int(100000, 999999);

        $otpRecord = Otp::create([
            'user_id' => $user->id,
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'last_sent_at' => now(),
        ]);

        Log::info('OTP generated', [
            'user_id' => $user->id,
            'otp_record_id' => $otpRecord->id,
            'otp_suffix' => substr($otp, -2),
        ]);

        $twilioResult = $this->twilioService->sendOtpDetailed($phone, $otp);
        Log::info('Twilio OTP response', [
            'user_id' => $user->id,
            'otp_record_id' => $otpRecord->id,
            'twilio_success' => $twilioResult['success'] ?? false,
            'twilio_sid' => $twilioResult['sid'] ?? null,
            'twilio_error' => $twilioResult['error'] ?? null,
        ]);

        if (! ($twilioResult['success'] ?? false)) {
            $otpRecord->delete();
            throw ValidationException::withMessages([
                'phone' => $twilioResult['error'] ?? 'Failed to send OTP via Twilio.',
            ]);
        }

        return $twilioResult;
    }

    public function verifyOtp(User $user, string $otp): bool
    {
        $record = Otp::query()
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        if (! $record || $record->expires_at->isPast()) {
            return false;
        }

        if ($record->attempts >= 5) {
            return false;
        }

        $record->increment('attempts');

        if (! Hash::check($otp, $record->otp_hash)) {
            return false;
        }

        Otp::query()->where('user_id', $user->id)->delete();

        return true;
    }

    private function guardRateLimit(User $user): void
    {
        $lastOtp = Otp::query()
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        if ($lastOtp && $lastOtp->last_sent_at && $lastOtp->last_sent_at->gt(now()->subMinute())) {
            throw ValidationException::withMessages([
                'phone' => 'Please wait at least 60 seconds before requesting a new OTP.',
            ]);
        }
    }
}
