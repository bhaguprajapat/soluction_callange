<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MapController extends Controller
{
    public function geocode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $apiKey = config('services.maps.api_key');
        if (! $apiKey) {
            return response()->json([
                'message' => 'Google Maps API key is not configured.',
            ], 500);
        }

        $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/geocode/json', [
            'address' => $validated['address'],
            'key' => $apiKey,
        ]);

        if (! $response->successful()) {
            return response()->json([
                'message' => 'Unable to resolve location at the moment.',
            ], 422);
        }

        $result = data_get($response->json(), 'results.0');
        if (! $result) {
            return response()->json([
                'message' => 'No location found for this search.',
            ], 404);
        }

        return response()->json([
            'latitude' => data_get($result, 'geometry.location.lat'),
            'longitude' => data_get($result, 'geometry.location.lng'),
            'formatted_address' => data_get($result, 'formatted_address'),
        ]);
    }
}

