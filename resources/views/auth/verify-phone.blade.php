@extends('layouts.app')

@section('title', 'Verify Phone - AutoRescue AI')

@section('content')
    <div class="card" style="max-width: 560px; margin: 8vh auto;">
        <h1 style="margin-top:0;">Phone Verification</h1>
        <p class="muted">A verified mobile number is required before dashboard access.</p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <div style="display:grid; gap:10px;">
            <input type="text" id="phone" placeholder="+15551234567" style="padding:12px; border:1px solid #dde5f0; border-radius:8px;">
            <button type="button" id="sendOtp" class="btn btn-primary">Send OTP</button>
        </div>

        <form method="POST" action="{{ route('phone.verify.confirm') }}" style="margin-top:16px;">
            @csrf
            <input type="text" name="otp" maxlength="6" placeholder="Enter 6-digit OTP" style="width:100%; padding:12px; border:1px solid #dde5f0; border-radius:8px;">
            <button type="submit" class="btn btn-primary" style="margin-top:10px; width:100%;">Verify OTP</button>
        </form>

        <p id="otpMessage" class="muted" style="margin-top:12px;"></p>
    </div>

    <script>
        const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        const otpEndpoint = @json(url('/send-otp'));

        document.getElementById('sendOtp').addEventListener('click', async () => {
            console.log('OTP button clicked');
            const phone = document.getElementById('phone').value.trim();
            const status = document.getElementById('otpMessage');

            if (!phone) {
                status.textContent = 'Phone number is required.';
                return;
            }

            const requestPayload = { phone };
            console.log('Sending OTP request', { endpoint: otpEndpoint, payload: requestPayload });

            try {
                const response = await fetch(otpEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(requestPayload)
                });

                const raw = await response.text();
                console.log('OTP API raw response', { status: response.status, body: raw });

                let payload = {};
                try {
                    payload = raw ? JSON.parse(raw) : {};
                } catch (e) {
                    payload = { error: 'Server returned an unexpected response format.' };
                }

                if (response.ok && payload.success) {
                    status.textContent = payload.message || 'OTP sent successfully.';
                    return;
                }

                const firstValidationError = payload.errors ? Object.values(payload.errors)[0]?.[0] : null;
                status.textContent = payload.error || firstValidationError || payload.message || 'Unable to send OTP.';
            } catch (error) {
                console.error('OTP request failed', error);
                status.textContent = 'Network error while sending OTP.';
            }
        });
    </script>
@endsection
