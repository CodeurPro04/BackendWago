<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverScheduledJobsLogicTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_does_not_see_job_scheduled_too_far_in_future(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $driver = User::factory()->create([
            'role' => 'driver',
            'profile_status' => 'approved',
            'is_available' => true,
        ]);

        $futureBooking = $this->createBooking($customer, now()->addHours(3)->toIso8601String());

        $response = $this->getJson("/api/drivers/{$driver->id}/jobs");

        $response->assertOk();
        $ids = array_map(fn (array $job) => (int) $job['id'], $response->json('jobs') ?? []);
        $this->assertNotContains((int) $futureBooking->id, $ids);
    }

    public function test_driver_sees_job_when_slot_is_close(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $driver = User::factory()->create([
            'role' => 'driver',
            'profile_status' => 'approved',
            'is_available' => true,
        ]);

        $nearBooking = $this->createBooking($customer, now()->addMinutes(10)->toIso8601String());

        $response = $this->getJson("/api/drivers/{$driver->id}/jobs");

        $response->assertOk();
        $ids = array_map(fn (array $job) => (int) $job['id'], $response->json('jobs') ?? []);
        $this->assertContains((int) $nearBooking->id, $ids);
    }

    public function test_driver_cannot_accept_job_too_early_before_slot(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $driver = User::factory()->create([
            'role' => 'driver',
            'profile_status' => 'approved',
            'is_available' => true,
        ]);

        $futureBooking = $this->createBooking($customer, now()->addHours(2)->toIso8601String());

        $response = $this->postJson("/api/jobs/{$futureBooking->id}/accept", [
            'driver_id' => $driver->id,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'Mission programmee: acceptance disponible 20 minutes avant le creneau.');
    }

    private function createBooking(User $customer, string $scheduledAt): Booking
    {
        return Booking::query()->create([
            'customer_id' => $customer->id,
            'driver_id' => null,
            'status' => 'pending',
            'service' => 'Lavage complet',
            'vehicle' => 'SUV',
            'wash_type_key' => 'complete',
            'address' => 'Abidjan, Cocody',
            'latitude' => 5.3364,
            'longitude' => -4.0267,
            'price' => 4000,
            'scheduled_at' => $scheduledAt,
            'customer_phone' => $customer->phone,
            'before_photos' => [],
            'after_photos' => [],
        ]);
    }
}

