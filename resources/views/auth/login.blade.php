@extends('layouts.app')

@section('title', 'Login - AutoRescue AI')

@section('content')
    <div class="card" style="max-width: 560px; margin: 8vh auto;">
        <h1 style="margin-top:0;">AutoRescue AI</h1>
        <p class="muted">Secure emergency access with Google OAuth and live location verification.</p>

        @if ($errors->any())
            <div class="error">
                {{ $errors->first() }}
            </div>
        @endif

        <form id="googleLoginForm" method="POST" action="{{ route('auth.google.redirect') }}">
            @csrf
            <input type="hidden" name="latitude" id="latitude">
            <input type="hidden" name="longitude" id="longitude">
            <button type="button" class="btn btn-primary" id="googleLoginBtn" style="width:100%;">
                Continue with Google
            </button>
        </form>

        <p class="muted" id="locationStatus" style="margin-top:14px;"></p>
    </div>

    <script>
        const statusEl = document.getElementById('locationStatus');
        const btn = document.getElementById('googleLoginBtn');
        const form = document.getElementById('googleLoginForm');

        btn.addEventListener('click', () => {
            statusEl.textContent = 'Requesting location permission...';

            if (!navigator.geolocation) {
                statusEl.textContent = 'Location access is required for emergency services';
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    document.getElementById('latitude').value = position.coords.latitude;
                    document.getElementById('longitude').value = position.coords.longitude;
                    statusEl.textContent = 'Location verified. Redirecting to Google login...';
                    form.submit();
                },
                () => {
                    statusEl.textContent = 'Location access is required for emergency services';
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );
        });
    </script>
@endsection

