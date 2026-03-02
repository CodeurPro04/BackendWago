<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverInboxUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public User $driver, public string $reason = 'inbox_changed')
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('drivers.inbox')];
    }

    public function broadcastAs(): string
    {
        return 'driver.inbox.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'driver_id' => (int) $this->driver->id,
            'reason' => $this->reason,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
