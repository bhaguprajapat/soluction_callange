<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(['email' => 'police@autorescue.local'], [
            'name' => 'Police Control',
            'google_id' => null,
            'role' => 'police',
            'phone' => '+1000000001',
            'phone_verified_at' => now(),
            'password' => null,
        ]);

        User::query()->updateOrCreate(['email' => 'fire@autorescue.local'], [
            'name' => 'Fire Station',
            'google_id' => null,
            'role' => 'fire',
            'phone' => '+1000000002',
            'phone_verified_at' => now(),
            'password' => null,
        ]);

        User::query()->updateOrCreate(['email' => 'ambulance@autorescue.local'], [
            'name' => 'Ambulance Control',
            'google_id' => null,
            'role' => 'ambulance',
            'phone' => '+1000000003',
            'phone_verified_at' => now(),
            'password' => null,
        ]);
    }
}
