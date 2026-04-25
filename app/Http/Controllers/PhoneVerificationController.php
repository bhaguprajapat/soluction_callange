<?php

namespace App\Http\Controllers;

use App\Services\OTPService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PhoneVerificationController extends Controller
{
    public function __construct(private readonly OTPService $otpService)
    {
    }

    public function show(Request $request)
    {
        if ($request->user()->hasVerifiedPhone()) {
            return redirect()->route('dashboard');
        }

        return view('auth.verify-phone');
    }

    public function sendOtp(Request $request): JsonResponse
    {
        Log::info('OTP Request Received', ['phone' => $request->input('phone'), 'user_id' => $request->user()?->id]);

        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'regex:/^\+?[1-9]\d{7,14}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid phone number.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $phone = $validator->validated()['phone'];
            $result = $this->otpService->sendOtp($request->user(), $phone);

            $request->session()->put('pending_phone', $phone);

            Log::info('OTP flow completed', ['user_id' => $request->user()?->id, 'phone' => $phone, 'result' => $result]);

            return response()->json([
                'success' => true,
                'message' => 'OTP sent successfully.',
                'provider' => $result['provider'] ?? 'twilio',
            ]);
        } catch (ValidationException $e) {
            Log::warning('OTP send validation/rate-limit blocked', [
                'user_id' => $request->user()?->id,
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'error' => collect($e->errors())->flatten()->first() ?: 'Unable to send OTP.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('OTP send failed', [
                'user_id' => $request->user()?->id,
                'phone' => $request->input('phone'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        $user = $request->user();
        $phone = $request->session()->get('pending_phone', $user->phone);

        if (! $phone) {
            return back()->withErrors(['phone' => 'Phone number is required before OTP verification.']);
        }

        $valid = $this->otpService->verifyOtp($user, $validated['otp']);
        if (! $valid) {
            return back()->withErrors(['otp' => 'Invalid or expired OTP.']);
        }

        $user->update([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ]);

        $request->session()->forget('pending_phone');

        return redirect()->route('dashboard')->with('status', 'Phone number verified successfully.');
    }
}
