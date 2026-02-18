<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = [
        'customer_id',
        'driver_id',
        'status',
        'service',
        'vehicle',
        'wash_type_key',
        'address',
        'latitude',
        'longitude',
        'price',
        'scheduled_at',
        'notes',
        'customer_phone',
        'driver_arrived_at',
        'wash_started_at',
        'completed_at',
        'cancelled_reason',
        'customer_rating',
        'customer_review',
        'before_photos',
        'after_photos',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'price' => 'integer',
            'driver_arrived_at' => 'datetime',
            'wash_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'customer_rating' => 'integer',
            'before_photos' => 'array',
            'after_photos' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
