<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingCancellationRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_cancel_before_driver_arrival(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'wallet_balance' => 5000,
        ]);

        $booking = $this->createBooking($customer, 'accepted');

        $response = $this->patchJson("/api/bookings/{$booking->id}/cancel", [
            'customer_id' => $customer->id,
            'reason' => 'changed_mind',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('booking.status', 'cancelled');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'cancelled',
            'cancelled_reason' => 'changed_mind',
        ]);
    }

    public function test_customer_cannot_cancel_after_driver_arrival(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'wallet_balance' => 5000,
        ]);

        $booking = $this->createBooking($customer, 'arrived');

        $response = $this->patchJson("/api/bookings/{$booking->id}/cancel", [
            'customer_id' => $customer->id,
            'reason' => 'too_late',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'Impossible d annuler: la mission a deja demarre ou est terminee.');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'arrived',
        ]);
    }

    public function test_customer_cannot_cancel_after_wash_started(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'wallet_balance' => 5000,
        ]);

        $booking = $this->createBooking($customer, 'washing');

        $response = $this->patchJson("/api/bookings/{$booking->id}/cancel", [
            'customer_id' => $customer->id,
            'reason' => 'too_late',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'Impossible d annuler: la mission a deja demarre ou est terminee.');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'washing',
        ]);
    }

    public function test_customer_cannot_cancel_another_customer_booking(): void
    {
        $owner = User::factory()->create([
            'role' => 'customer',
            'wallet_balance' => 5000,
        ]);
        $otherCustomer = User::factory()->create([
            'role' => 'customer',
            'wallet_balance' => 5000,
        ]);

        $booking = $this->createBooking($owner, 'accepted');

        $response = $this->patchJson("/api/bookings/{$booking->id}/cancel", [
            'customer_id' => $otherCustomer->id,
            'reason' => 'not_mine',
        ]);

        $response
            ->assertStatus(403)
            ->assertJsonPath('message', 'Reservation non autorisee pour ce client.');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'accepted',
        ]);
    }

    public function test_driver_cannot_cancel_mission_from_transition_endpoint(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'wallet_balance' => 5000,
        ]);
        $driver = User::factory()->create([
            'role' => 'driver',
            'profile_status' => 'approved',
            'is_available' => true,
        ]);

        $booking = $this->createBooking($customer, 'arrived', $driver->id);

        $response = $this->postJson("/api/jobs/{$booking->id}/transition", [
            'driver_id' => $driver->id,
            'action' => 'cancel',
        ]);

        $response
            ->assertStatus(403)
            ->assertJsonPath('message', 'Seul le client peut annuler la mission.');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'arrived',
        ]);
    }

    public function test_driver_cannot_accept_multiple_active_missions(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'wallet_balance' => 5000,
        ]);
        $driver = User::factory()->create([
            'role' => 'driver',
            'profile_status' => 'approved',
            'is_available' => true,
        ]);

        $activeBooking = $this->createBooking($customer, 'accepted', $driver->id);
        $pendingBooking = $this->createBooking($customer, 'pending');

        $response = $this->postJson("/api/jobs/{$pendingBooking->id}/accept", [
            'driver_id' => $driver->id,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'Vous avez deja une mission en cours. Terminez-la avant d en accepter une autre.');

        $this->assertDatabaseHas('bookings', [
            'id' => $activeBooking->id,
            'status' => 'accepted',
            'driver_id' => $driver->id,
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $pendingBooking->id,
            'status' => 'pending',
            'driver_id' => null,
        ]);
    }

    private function createBooking(User $customer, string $status, ?int $driverId = null): Booking
    {
        return Booking::query()->create([
            'customer_id' => $customer->id,
            'driver_id' => $driverId,
            'status' => $status,
            'service' => 'Lavage complet',
            'vehicle' => 'SUV',
            'wash_type_key' => 'complete',
            'address' => 'Abidjan, Cocody',
            'latitude' => 5.3364,
            'longitude' => -4.0267,
            'price' => 4000,
            'scheduled_at' => now()->toIso8601String(),
            'customer_phone' => $customer->phone,
            'before_photos' => [],
            'after_photos' => [],
        ]);
    }
}
