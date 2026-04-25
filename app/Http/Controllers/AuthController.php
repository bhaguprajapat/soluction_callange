<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function redirectToGoogle(Request $request): RedirectResponse
    {
        // dd(config('services.google.redirect'));
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ], [
            'latitude.required' => 'Location access is required for emergency services',
            'longitude.required' => 'Location access is required for emergency services',
        ]);

        $request->session()->put('oauth_location', $validated);

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        $location = $request->session()->get('oauth_location');
        if (! $location) {
            return redirect()
                ->route('login')
                ->withErrors(['location' => 'Location access is required for emergency services']);
        }

        $googleUser = Socialite::driver('google')->user();

        $user = User::query()->updateOrCreate(
            ['email' => $googleUser->getEmail()],
            [
                'name' => $googleUser->getName() ?: $googleUser->getNickname() ?: 'AutoRescue User',
                'google_id' => $googleUser->getId(),
                'password' => Str::password(32),
                'role' => 'user',
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
            ]
        );

        Auth::login($user, true);
        $request->session()->forget('oauth_location');

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

