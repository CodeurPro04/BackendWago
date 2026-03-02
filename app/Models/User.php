<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\DriverNotification;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
        'wallet_balance',
        'is_available',
        'latitude',
        'longitude',
        'first_name',
        'last_name',
        'avatar_url',
        'bio',
        'membership',
        'rating',
        'profile_status',
        'account_step',
        'documents',
        'documents_status',
        'is_banned',
        'banned_at',
        'banned_reason',
        'expo_push_token',
        'app_version_last_seen',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'wallet_balance' => 'integer',
            'is_available' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'rating' => 'float',
            'account_step' => 'integer',
            'documents' => 'array',
            'is_banned' => 'boolean',
            'banned_at' => 'datetime',
        ];
    }

    public function customerBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'customer_id');
    }

    public function driverBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'driver_id');
    }

    public function activeDriverJob(): HasOne
    {
        return $this->hasOne(Booking::class, 'driver_id')
            ->whereIn('status', ['accepted', 'en_route', 'arrived', 'washing'])
            ->latestOfMany();
    }

    public function driverNotifications(): HasMany
    {
        return $this->hasMany(DriverNotification::class, 'user_id');
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'user_id');
    }
}
