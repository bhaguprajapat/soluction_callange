<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmergencyController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\PhoneVerificationController;
use App\Http\Controllers\ResponseController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Twilio\Rest\Client;
Route::match(['GET', 'POST'], '/twilio/voice', function (Request $request) {

    return response('<?xml version="1.0" encoding="UTF-8"?>
    <Response>
        <Say voice="alice">Hello, this is a simple Twilio webhook test call</Say>
    </Response>', 200)
    ->header('Content-Type', 'text/xml');
});
Route::get('test-call', function () {

    $client = new Client(
        env('TWILIO_SID'),
        env('TWILIO_TOKEN')
    );

    $call = $client->calls->create(
        "+916350312313", // tumhara number
        env('TWILIO_FROM'),
        [
            "url" => "https://affair-bucked-ecologist.ngrok-free.dev/twilio/voice"
        ]
    );

    return "Call Sent: " . $call->sid;
});
// Route::get('test_call', function () {

//     $sid = env('TWILIO_SID');
//     $token = env('TWILIO_TOKEN');
//     $twilioNumber = env('TWILIO_VOICE_FROM');

//     $client = new Client($sid, $token);

//     try {

//         $call = $client->calls->create(
//             '+916350312313', // 👈 tumhara number (E.164 format)
//             $twilioNumber,
//             [
//                 "twiml" => "<Response><Say voice='alice'>Hello! This is a test call from your Laravel Twilio integration.</Say></Response>"
//             ]
//         );

//         return "✅ Call initiated. SID: " . $call->sid;

//     } catch (\Exception $e) {
//         return "❌ Error: " . $e->getMessage();
//     }

// });

Route::match(['GET', 'POST'], 'twilio/voice', [ResponseController::class, 'voiceWebhook'])->name('twilio.voice');
Route::match(['GET', 'POST'], '/twilio/handle', [ResponseController::class, 'handleVoice'])->name('twilio.handle');
Route::match(['GET', 'POST'], '/twilio/voice/ivr', [ResponseController::class, 'voiceIntro'])->name('twilio.voice.ivr');
Route::match(['GET', 'POST'], '/handle-response', [ResponseController::class, 'handleResponse'])->name('twilio.handle-response');

Route::middleware('guest')->group(function (): void {
    Route::get('/', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/auth/google/redirect', [AuthController::class, 'redirectToGoogle'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/verify-phone', [PhoneVerificationController::class, 'show'])->name('phone.verify.show');
    Route::post('/send-otp', [PhoneVerificationController::class, 'sendOtp'])->name('send-otp');
    Route::post('/verify-phone/send-otp', [PhoneVerificationController::class, 'sendOtp'])->name('phone.verify.send-otp');
    Route::post('/verify-phone/confirm', [PhoneVerificationController::class, 'verifyOtp'])->name('phone.verify.confirm');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/api/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
    Route::match(['GET', 'POST'], '/test-call', [ResponseController::class, 'testCall'])->name('twilio.test-call');
});

Route::middleware(['auth', 'phone.verified'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('api')->group(function (): void {
        Route::post('/emergencies/trigger', [EmergencyController::class, 'trigger']);
        Route::get('/dashboard/alerts', [DashboardController::class, 'alerts']);
        Route::get('/maps/geocode', [MapController::class, 'geocode']);
    });
});
