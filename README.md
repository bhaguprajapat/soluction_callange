# AutoRescue AI

AI-powered emergency response platform built with Laravel 12, MySQL, and Google Gemini API.

## Features

- Google OAuth-only login (no manual registration)
- Google email must be verified before sign-in is accepted
- Post-login mobile OTP verification (Twilio SMS, 5-minute expiry)
- Mandatory geolocation before OAuth redirect
- Dashboard with 5 emergency types (`fire`, `attack`, `medical`, `kidnap`, `other`)
- Double-click emergency trigger to reduce accidental dispatch
- Double-click trigger now asks for:
  - Current GPS location
  - Other location via Google geocoding picker flow
- Emergency pipeline:
  - Save emergency
  - Analyze using Gemini API
  - Save AI response
  - Auto-dispatch responders (police, fire, ambulance)
- Alert channels:
  - Email (Laravel Mail)
  - SMS (Twilio)
  - WhatsApp (Twilio)
  - AI voice call with Twilio `<Say>` TTS
  - Dashboard notifications
- Multi-signal detection:
  - If >3 emergencies within same geo area in 5 minutes:
    - Mark as `CRITICAL CLUSTER EVENT`
    - Elevate severity to `critical`
    - Trigger broadcast alert
- Input validation, auth-protected routes, and duplicate trigger protection

## Tech Stack

- Laravel 12
- PHP 8.2+
- MySQL 8+
- Google Socialite (Google OAuth)
- Google Gemini API (`generateContent`)
- Twilio REST API (SMS, WhatsApp, Voice)

## Database Schema

### `users`
- `id`
- `name`
- `email`
- `google_id`
- `role` (`user`, `police`, `ambulance`, `fire`)
- `phone`
- `phone_verified_at`
- `latitude`
- `longitude`
- timestamps

### `otps`
- `id`
- `user_id`
- `otp_hash`
- `expires_at`
- `attempts`
- `last_sent_at`
- timestamps

### `emergencies`
- `id`
- `user_id`
- `type`
- `latitude`
- `longitude`
- `severity`
- `ai_response` (JSON)
- `status` (`active`, `resolved`)
- timestamps

### `alerts`
- `id`
- `emergency_id`
- `user_id`
- `alert_type` (`sms`, `whatsapp`, `email`, `call`, `dashboard`, `broadcast`)
- `status`
- timestamps

## Routes

### Main Pages
- `GET /` -> Login page
- `GET /dashboard` -> Dashboard page

### Auth
- `POST /auth/google/redirect`
- `GET /auth/google/callback`
- `GET /verify-phone`
- `POST /verify-phone/send-otp`
- `POST /verify-phone/confirm`
- `POST /logout`

### REST APIs
- `GET /api/auth/google/callback` (session-auth callback alias)
- `POST /api/emergencies/trigger`
- `GET /api/dashboard/alerts`
- `GET /api/maps/geocode?address=...`

## Setup

1. Install dependencies:
```bash
composer install
```

2. Configure environment:
```bash
cp .env.example .env
php artisan key:generate
```

3. Update `.env` with MySQL:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=autorescue_ai
DB_USERNAME=root
DB_PASSWORD=
```

4. Configure Google OAuth:
```env
GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret
GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback
```

5. Configure Gemini API:
```env
GEMINI_API_KEY=your_gemini_api_key
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta
GEMINI_MODEL=gemini-1.5-flash
```

6. Configure Twilio and Google Maps:
```env
TWILIO_ACCOUNT_SID=your_sid
TWILIO_AUTH_TOKEN=your_auth_token
TWILIO_SMS_FROM=+1XXXXXXXXXX
TWILIO_WHATSAPP_FROM=+14155238886
TWILIO_VOICE_FROM=+1XXXXXXXXXX
GOOGLE_MAPS_API_KEY=your_google_maps_key
```

7. Migrate and seed:
```bash
php artisan migrate --seed
```

8. Run app:
```bash
php artisan serve
```

## Sample Seeded Responders

- `police@autorescue.local` (`role=police`)
- `fire@autorescue.local` (`role=fire`)
- `ambulance@autorescue.local` (`role=ambulance`)

## Google Cloud Deployment Notes

- Set `APP_ENV=production`, `APP_DEBUG=false`
- Use Cloud SQL (MySQL) and set DB env vars
- Use Secret Manager for `GOOGLE_CLIENT_SECRET` and `GEMINI_API_KEY`
- Set `APP_URL` to production URL and update `GOOGLE_REDIRECT_URI`
- Use queue worker for large-scale alert dispatch

## Google Maps Picker Guidance

- Current implementation keeps existing UI design unchanged and uses a JS prompt + backend geocoding API.
- To switch to a richer map picker without redesigning layout:
  - Load Google Maps JS Places library.
  - Replace prompt with an autocomplete input in a temporary modal container.
  - Submit selected place coordinates to the same `POST /api/emergencies/trigger` endpoint.

## Important Notes

- If Twilio credentials are missing, Twilio service safely logs simulated delivery.
- OTP is hashed at rest, expires in 5 minutes, and is rate-limited.
- Gemini service falls back to deterministic content on API failure.
