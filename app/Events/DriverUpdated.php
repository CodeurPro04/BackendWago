<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public User $driver)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('backoffice.drivers')];
    }

    public function broadcastAs(): string
    {
        return 'driver.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => (int) $this->driver->id,
            'profile_status' => (string) $this->driver->profile_status,
            'documents_status' => (string) $this->driver->documents_status,
            'is_available' => (bool) $this->driver->is_available,
            'is_banned' => (bool) $this->driver->is_banned,
            'updated_at' => optional($this->driver->updated_at)->toIso8601String(),
        ];
    }
}
