<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAnnouncement extends Model
{
    protected $fillable = [
        'channel',
        'title',
        'body',
        'audience',
        'route',
        'sent_count',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'sent_count' => 'integer',
            'meta' => 'array',
        ];
    }
}

