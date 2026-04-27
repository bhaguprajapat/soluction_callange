<?php

namespace App\Services;

use App\Models\Emergency;
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

    public function dispatchEmergencyBroadcast(
        Emergency $emergency,
        iterable $responders,
        string $message,
        ?string $voiceMessage = null
    ): array
    {
        $responders = collect($responders)
            ->filter(fn (User $user) => ! empty($user->phone))
            ->values();

        if ($responders->isEmpty()) {
            return ['sms' => [], 'whatsapp' => [], 'call' => []];
        }

        if (! $this->isConfigured()) {
            Log::error('Twilio credentials missing. Cannot dispatch emergency broadcast.', [
                'emergency_id' => $emergency->id,
                'sid_present' => (bool) config('services.twilio.account_sid'),
                'token_present' => (bool) config('services.twilio.auth_token'),
            ]);

            $failed = $responders->map(fn (User $u) => ['user_id' => $u->id, 'ok' => false])->all();

            return ['sms' => $failed, 'whatsapp' => $failed, 'call' => $failed];
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

        $callResponses = $responders->map(function (User $user) use ($emergency) {
            $result = $this->placeInteractiveCall($user->phone, $emergency->id, $user->id);

            return [
                'user_id' => $user->id,
                'ok' => (bool) ($result['success'] ?? false),
                'sid' => $result['sid'] ?? null,
                'error' => $result['error'] ?? null,
            ];
        })->all();

        return [
            'sms' => $this->normalizePoolResult($responders, $smsResponses),
            'whatsapp' => $this->normalizePoolResult($responders, $whatsResponses),
            'call' => $callResponses,
        ];
    }

    public function placeInteractiveCall(string $to, int $emergencyId, int $responderId): array
    {
        $webhookUrl = $this->voiceWebhookUrl($emergencyId, $responderId);
        $from = (string) config('services.twilio.voice_from');

        Log::info('Twilio voice call attempt', [
            'to' => $to,
            'from' => $from,
            'webhook_url' => $webhookUrl,
            'emergency_id' => $emergencyId,
            'responder_id' => $responderId,
        ]);
        Log::info('Calling: '.$to);

        if (! $this->isConfigured() || ! $from) {
            return [
                'success' => false,
                'error' => 'Twilio voice configuration is missing.',
            ];
        }

        if (! $this->isE164($to) || ! $this->isE164($from)) {
            $error = 'Phone numbers must be in E.164 format (+countrycode number).';
            Log::error('Twilio voice call blocked', [
                'error' => $error,
                'to' => $to,
                'from' => $from,
            ]);

            return [
                'success' => false,
                'error' => $error,
            ];
        }

        if (! $this->isPublicWebhookUrl($webhookUrl)) {
            $error = 'Twilio voice webhook URL must be public (use ngrok/live URL, not localhost).';
            Log::error('Twilio voice call blocked', [
                'error' => $error,
                'webhook_url' => $webhookUrl,
            ]);

            return [
                'success' => false,
                'error' => $error,
            ];
        }

        try {
            $call = $this->client()->calls->create(
                $to,
                $from,
                [
                    'url' => $webhookUrl,
                    'method' => 'POST',
                ]
            );

            Log::info('Twilio voice call created', [
                'sid' => $call->sid ?? null,
                'status' => $call->status ?? null,
                'to' => $to,
                'webhook_url' => $webhookUrl,
            ]);
            Log::info('Twilio response SID: '.($call->sid ?? 'N/A'));

            return [
                'success' => true,
                'sid' => $call->sid ?? null,
                'status' => $call->status ?? null,
            ];
        } catch (TwilioException $e) {
            Log::error('Twilio voice call failed', [
                'to' => $to,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'webhook_url' => $webhookUrl,
            ]);
            Log::error('CALL ERROR: '.$e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            Log::error('Twilio voice call unexpected failure', [
                'to' => $to,
                'error' => $e->getMessage(),
                'webhook_url' => $webhookUrl,
            ]);
            Log::error('CALL ERROR: '.$e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
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

    private function voiceIvrUrl(int $emergencyId, int $responderId): string
    {
        $endpoint = $this->resolveVoiceWebhookEndpoint();
        $query = http_build_query([
            'emergency_id' => $emergencyId,
            'responder_id' => $responderId,
            'attempt' => 0,
        ]);

        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return $endpoint.$separator.$query;
    }

    private function voiceWebhookUrl(int $emergencyId, int $responderId): string
    {
        return $this->voiceIvrUrl($emergencyId, $responderId);
    }

    private function resolveVoiceWebhookEndpoint(): string
    {
        $configured = trim((string) config('services.twilio.voice_webhook_url'));
        $appUrl = trim((string) config('app.url'));

        $candidates = array_values(array_filter([$configured, $appUrl]));

        foreach ($candidates as $candidate) {
            $endpoint = $this->normalizeVoiceEndpoint($candidate);
            if ($this->isAbsoluteHttpUrl($endpoint)) {
                return $endpoint;
            }
        }

        $fallback = 'http://localhost/twilio/voice';
        Log::warning('Twilio voice webhook endpoint fallback applied', [
            'configured' => $configured ?: null,
            'app_url' => $appUrl ?: null,
            'fallback' => $fallback,
        ]);

        return $fallback;
    }

    private function normalizeVoiceEndpoint(string $candidate): string
    {
        $parts = parse_url($candidate);
        if (! is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');

        if ($path === '' || $path === '/') {
            $path = '/twilio/voice';
        }

        $url = "{$scheme}://{$host}{$port}".'/'.ltrim($path, '/');
        if ($query !== '') {
            $url .= '?'.$query;
        }

        return rtrim($url, '/');
    }

    private function isAbsoluteHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    private function isPublicWebhookUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! $host || ! $scheme || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }

        $blockedHosts = ['localhost', '127.0.0.1', '::1'];
        if (in_array(strtolower($host), $blockedHosts, true)) {
            return false;
        }

        if (preg_match('/\.local$/i', $host)) {
            return false;
        }

        return true;
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

    private function isE164(string $phone): bool
    {
        return (bool) preg_match('/^\+[1-9]\d{7,14}$/', $phone);
    }

    public function makeCall(string $to, string $message): array
    {
        $from = (string) config('services.twilio.voice_from');
        if (! $this->isConfigured() || $from === '') {
            Log::warning('Twilio makeCall skipped: missing configuration', [
                'to' => $this->maskPhone($to),
                'sid_present' => (bool) config('services.twilio.account_sid'),
                'token_present' => (bool) config('services.twilio.auth_token'),
                'voice_from_present' => (bool) config('services.twilio.voice_from'),
            ]);

            return [
                'success' => false,
                'error' => 'Twilio voice configuration is missing.',
            ];
        }

        if (! $this->isE164($to) || ! $this->isE164($from)) {
            $error = 'Phone numbers must be in E.164 format (+countrycode number).';
            Log::warning('Twilio makeCall blocked: invalid number format', [
                'to' => $to,
                'from' => $from,
                'error' => $error,
            ]);

            return [
                'success' => false,
                'error' => $error,
            ];
        }

        $safeMessage = trim($message) !== '' ? $message : 'Emergency alert received.';
        $twiml = "<Response><Say voice='alice'>".htmlspecialchars($safeMessage, ENT_QUOTES, 'UTF-8').'</Say></Response>';

        try {
            Log::info('Twilio makeCall attempt', [
                'to' => $to,
                'from' => $from,
            ]);

            $call = $this->client()->calls->create(
                $to,
                $from,
                [
                    'twiml' => $twiml,
                ]
            );

            Log::info('Twilio makeCall success', [
                'to' => $to,
                'sid' => $call->sid ?? null,
                'status' => $call->status ?? null,
            ]);

            return [
                'success' => true,
                'sid' => $call->sid ?? null,
            ];
        } catch (TwilioException $e) {
            Log::error('Twilio makeCall failed', [
                'to' => $to,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            Log::error('Twilio makeCall failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
