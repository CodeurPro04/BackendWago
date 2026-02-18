<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['phone' => '+2250700000001', 'role' => 'customer'],
            [
                'name' => 'Client Demo',
                'first_name' => 'Client',
                'last_name' => 'Demo',
                'email' => 'customer.demo@ziwago.local',
                'password' => Str::password(32),
                'wallet_balance' => 30000,
                'is_available' => false,
                'profile_status' => 'approved',
                'account_step' => 8,
            ]
        );

        User::query()->updateOrCreate(
            ['phone' => '+2250700001001', 'role' => 'driver'],
            [
                'name' => 'Ibrahim',
                'first_name' => 'Ibrahim',
                'last_name' => 'Driver',
                'email' => 'driver.ibrahim@ziwago.local',
                'password' => Str::password(32),
                'wallet_balance' => 0,
                'is_available' => true,
                'latitude' => 5.3364,
                'longitude' => -4.0267,
                'profile_status' => 'pending',
                'account_step' => 7,
            ]
        );

        User::query()->updateOrCreate(
            ['phone' => '+2250700001002', 'role' => 'driver'],
            [
                'name' => 'Marie',
                'first_name' => 'Marie',
                'last_name' => 'Driver',
                'email' => 'driver.marie@ziwago.local',
                'password' => Str::password(32),
                'wallet_balance' => 0,
                'is_available' => true,
                'latitude' => 5.3480,
                'longitude' => -4.0210,
                'profile_status' => 'approved',
                'account_step' => 8,
            ]
        );
    }
}
