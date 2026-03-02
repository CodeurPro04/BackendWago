<?php

namespace App\Events;

use App\Models\AdminAnnouncement;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AnnouncementCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public AdminAnnouncement $announcement)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('backoffice.announcements')];
    }

    public function broadcastAs(): string
    {
        return 'announcement.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => (int) $this->announcement->id,
            'title' => (string) $this->announcement->title,
            'audience' => (string) $this->announcement->audience,
            'sent_count' => (int) $this->announcement->sent_count,
            'created_at' => optional($this->announcement->created_at)->toIso8601String(),
        ];
    }
}
