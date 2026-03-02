<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Booking $booking)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('backoffice.bookings')];
    }

    public function broadcastAs(): string
    {
        return 'booking.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => (int) $this->booking->id,
            'status' => (string) $this->booking->status,
            'driver_id' => $this->booking->driver_id ? (int) $this->booking->driver_id : null,
            'customer_id' => (int) $this->booking->customer_id,
            'updated_at' => optional($this->booking->updated_at)->toIso8601String(),
        ];
    }
}
