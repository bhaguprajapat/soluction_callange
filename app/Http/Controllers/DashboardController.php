<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Emergency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $emergencies = Emergency::query()
            ->where('user_id', $user->id)
            ->latest()
            ->take(20)
            ->get();

        $alerts = Alert::query()
            ->where('user_id', $user->id)
            ->with('emergency')
            ->latest()
            ->take(20)
            ->get();

        return view('dashboard.index', compact('user', 'emergencies', 'alerts'));
    }

    public function alerts(Request $request): JsonResponse
    {
        $user = $request->user();

        $alerts = Alert::query()
            ->where('user_id', $user->id)
            ->with('emergency')
            ->latest()
            ->take(30)
            ->get()
            ->map(fn (Alert $alert) => [
                'id' => $alert->id,
                'alert_type' => $alert->alert_type,
                'status' => $alert->status,
                'emergency_type' => $alert->emergency?->type,
                'emergency_severity' => $alert->emergency?->severity,
                'created_at' => $alert->created_at?->toDateTimeString(),
            ]);

        return response()->json(['data' => $alerts]);
    }
}

