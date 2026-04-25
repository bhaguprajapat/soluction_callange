<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;

class TwilioService
{
    private ?Client $client = null;

    public function sendOtp(string $phone, string $otp): bool
    {
        $result = $this->sendOtpDetailed($phone, $otp);

        return (bool) ($result['success'] ?? false);
    }

    public function sendOtpDetailed(string $phone, string $otp): array
    {
        $message = "Your OTP is: {$otp}";

        if (! $this->isConfigured() || ! config('services.twilio.sms_from')) {
            Log::warning('Twilio OTP send blocked due missing configuration', [
                'phone' => $this->maskPhone($phone),
                'sid_present' => (bool) config('services.twilio.account_sid'),
                'token_present' => (bool) config('services.twilio.auth_token'),
                'from_present' => (bool) config('services.twilio.sms_from'),
            ]);

            return [
                'success' => false,
                'provider' => 'twilio',
                'error' => 'Twilio configuration is missing. Please set SID, Auth Token, and Twilio phone number.',
            ];
        }

        try {
            $result = $this->client()->messages->create($phone, [
                'from' => config('services.twilio.sms_from'),
                'body' => $message,
            ]);

            Log::info('Twilio OTP sent', [
                'phone' => $this->maskPhone($phone),
                'sid' => $result->sid ?? null,
                'status' => $result->status ?? null,
            ]);

            return [
                'success' => true,
                'provider' => 'twilio',
                'sid' => $result->sid ?? null,
            ];
        } catch (TwilioException $e) {
            Log::error('Twilio OTP failed', [
                'phone' => $this->maskPhone($phone),
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return [
                'success' => false,
                'provider' => 'twilio',
                'error' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            Log::error('Twilio OTP unexpected failure', [
                'phone' => $this->maskPhone($phone),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'provider' => 'twilio',
                'error' => 'Failed to send OTP. Please try again.',
            ];
        }
    }

    public function sendSms(string $to, string $message): bool
    {
        return $this->sendMessage($to, $message, config('services.twilio.sms_from'));
    }

    public function sendWhatsapp(string $to, string $message): bool
    {
        $from = config('services.twilio.whatsapp_from');
        $to = str_starts_with($to, 'whatsapp:') ? $to : 'whatsapp:'.$to;
        $from = $from && ! str_starts_with($from, 'whatsapp:') ? 'whatsapp:'.$from : $from;

        return $this->sendMessage($to, $message, $from);
    }

    public function callWithTts(string $to, string $message): bool
    {
        if (! $this->isConfigured() || ! config('services.twilio.voice_from')) {
            Log::info('Twilio call simulated', ['to' => $to, 'message' => $message]);

            return true;
        }

        $response = Http::asForm()
            ->withBasicAuth(config('services.twilio.account_sid'), config('services.twilio.auth_token'))
            ->post($this->baseUrl().'/Calls.json', [
                'To' => $to,
                'From' => config('services.twilio.voice_from'),
                'Twiml' => "<Response><Say voice='alice'>".htmlspecialchars($message, ENT_QUOTES).'</Say></Response>',
            ]);

        if (! $response->successful()) {
            Log::error('Twilio call failed', ['to' => $to, 'status' => $response->status(), 'body' => $response->body()]);
        }

        return $response->successful();
    }

    public function dispatchEmergencyBroadcast(iterable $responders, string $message, ?string $voiceMessage = null): array
    {
        $responders = collect($responders)
            ->filter(fn (User $user) => ! empty($user->phone))
            ->values();

        if ($responders->isEmpty()) {
            return ['sms' => [], 'whatsapp' => [], 'call' => []];
        }

        if (! $this->isConfigured()) {
            $mock = $responders->map(fn (User $u) => ['user_id' => $u->id, 'ok' => true])->all();

            return ['sms' => $mock, 'whatsapp' => $mock, 'call' => $mock];
        }

        $smsResponses = Http::pool(function (Pool $pool) use ($responders, $message) {
            return $responders->map(function (User $user) use ($pool, $message) {
                return $pool->as((string) $user->id)->asForm()->withBasicAuth(
                    config('services.twilio.account_sid'),
                    config('services.twilio.auth_token')
                )->post($this->baseUrl().'/Messages.json', [
                    'To' => $user->phone,
                    'From' => config('services.twilio.sms_from'),
                    'Body' => $message,
                ]);
            })->all();
        });

        $whatsResponses = Http::pool(function (Pool $pool) use ($responders, $message) {
            return $responders->map(function (User $user) use ($pool, $message) {
                return $pool->as((string) $user->id)->asForm()->withBasicAuth(
                    config('services.twilio.account_sid'),
                    config('services.twilio.auth_token')
                )->post($this->baseUrl().'/Messages.json', [
                    'To' => 'whatsapp:'.$user->phone,
                    'From' => $this->whatsappFrom(),
                    'Body' => $message,
                ]);
            })->all();
        });

        $voice = $voiceMessage ?: $message;

        $callResponses = Http::pool(function (Pool $pool) use ($responders, $voice) {
            return $responders->map(function (User $user) use ($pool, $voice) {
                return $pool->as((string) $user->id)->asForm()->withBasicAuth(
                    config('services.twilio.account_sid'),
                    config('services.twilio.auth_token')
                )->post($this->baseUrl().'/Calls.json', [
                    'To' => $user->phone,
                    'From' => config('services.twilio.voice_from'),
                    'Twiml' => "<Response><Say voice='alice'>".htmlspecialchars($voice, ENT_QUOTES).'</Say></Response>',
                ]);
            })->all();
        });

        return [
            'sms' => $this->normalizePoolResult($responders, $smsResponses),
            'whatsapp' => $this->normalizePoolResult($responders, $whatsResponses),
            'call' => $this->normalizePoolResult($responders, $callResponses),
        ];
    }

    private function sendMessage(string $to, string $message, ?string $from): bool
    {
        if (! $this->isConfigured() || ! $from) {
            Log::info('Twilio message simulated', ['to' => $to, 'from' => $from, 'message' => $message]);

            return true;
        }

        $response = Http::asForm()
            ->withBasicAuth(config('services.twilio.account_sid'), config('services.twilio.auth_token'))
            ->post($this->baseUrl().'/Messages.json', [
                'To' => $to,
                'From' => $from,
                'Body' => $message,
            ]);

        if (! $response->successful()) {
            Log::error('Twilio message failed', ['to' => $to, 'status' => $response->status(), 'body' => $response->body()]);
        }

        return $response->successful();
    }

    private function normalizePoolResult($responders, array $responses): array
    {
        return $responders->map(function (User $user) use ($responses) {
            $key = (string) $user->id;
            $ok = isset($responses[$key]) ? $responses[$key]->successful() : false;

            return ['user_id' => $user->id, 'ok' => $ok];
        })->all();
    }

    private function isConfigured(): bool
    {
        return (bool) (config('services.twilio.account_sid') && config('services.twilio.auth_token'));
    }

    private function client(): Client
    {
        if ($this->client) {
            return $this->client;
        }

        $this->client = new Client(
            config('services.twilio.account_sid'),
            config('services.twilio.auth_token')
        );

        return $this->client;
    }

    private function baseUrl(): string
    {
        return 'https://api.twilio.com/2010-04-01/Accounts/'.config('services.twilio.account_sid');
    }

    private function whatsappFrom(): ?string
    {
        $from = config('services.twilio.whatsapp_from');
        if (! $from) {
            return null;
        }

        return str_starts_with($from, 'whatsapp:') ? $from : 'whatsapp:'.$from;
    }

    private function maskPhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone) ?: $phone;
        $len = strlen($phone);

        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4).substr($phone, -4);
    }
}
