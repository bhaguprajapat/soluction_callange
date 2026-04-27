<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LocationIntelligenceService
{
    public function resolve(float $latitude, float $longitude, ?string $label = null): array
    {
        $mapsLink = sprintf('https://www.google.com/maps?q=%s,%s', $latitude, $longitude);

        $result = [
            'maps_link' => $mapsLink,
            'location_name' => $label ?: sprintf('%.5f, %.5f', $latitude, $longitude),
            'pincode' => null,
            'nearest_police_station' => 'Nearest police station info unavailable',
        ];

        $apiKey = config('services.maps.api_key');
        if (! $apiKey) {
            return $result;
        }

        try {
            $geo = Http::timeout(12)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'latlng' => "{$latitude},{$longitude}",
                'key' => $apiKey,
            ]);

            if ($geo->successful()) {
                $first = data_get($geo->json(), 'results.0');
                if ($first) {
                    $result['location_name'] = data_get($first, 'formatted_address', $result['location_name']);
                    $result['pincode'] = $this->extractPostalCode((array) data_get($first, 'address_components', []));
                }
            }

            $nearby = Http::timeout(12)->get('https://maps.googleapis.com/maps/api/place/nearbysearch/json', [
                'location' => "{$latitude},{$longitude}",
                'rankby' => 'distance',
                'type' => 'police',
                'key' => $apiKey,
            ]);

            if ($nearby->successful()) {
                $police = data_get($nearby->json(), 'results.0.name');
                if ($police) {
                    $result['nearest_police_station'] = $police;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Location intelligence lookup failed', [
                'error' => $e->getMessage(),
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
        }

        return $result;
    }

    private function extractPostalCode(array $components): ?string
    {
        foreach ($components as $component) {
            $types = (array) ($component['types'] ?? []);
            if (in_array('postal_code', $types, true)) {
                return (string) ($component['long_name'] ?? null);
            }
        }

        return null;
    }
}

