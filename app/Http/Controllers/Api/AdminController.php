<?php

namespace App\Http\Controllers\Api;

use App\Events\AnnouncementCreated;
use App\Events\DriverUpdated;
use App\Http\Controllers\Controller;
use App\Models\AdminAnnouncement;
use App\Models\Booking;
use App\Models\User;
use App\Services\DriverNotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(private readonly DriverNotificationService $notificationService)
    {
    }
    public function dashboard()
    {
        $bookings = Booking::query();
        $users = User::query();

        $stats = [
            'total_users' => (int) $users->count(),
            'total_customers' => (int) $users->clone()->where('role', 'customer')->count(),
            'total_drivers' => (int) $users->clone()->where('role', 'driver')->count(),
            'pending_driver_reviews' => (int) $users->clone()->where('role', 'driver')->whereIn('documents_status', ['submitted', 'pending'])->count(),
            'total_bookings' => (int) $bookings->count(),
            'active_bookings' => (int) $bookings->clone()->whereIn('status', ['pending', 'accepted', 'en_route', 'arrived', 'washing'])->count(),
            'completed_bookings' => (int) $bookings->clone()->where('status', 'completed')->count(),
            'cancelled_bookings' => (int) $bookings->clone()->where('status', 'cancelled')->count(),
            'gross_revenue' => (int) $bookings->clone()->where('status', 'completed')->sum('price'),
            'net_driver_payout' => (int) round($bookings->clone()->where('status', 'completed')->sum('price') * 0.8),
        ];

        return response()->json(['stats' => $stats]);
    }

    public function drivers(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'pending', 'submitted', 'approved', 'rejected'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = User::query()->where('role', 'driver');

        $status = $validated['status'] ?? 'all';
        if ($status === 'submitted') {
            $query->where('documents_status', 'submitted');
        } elseif ($status !== 'all') {
            $query->where('profile_status', $status);
        }

        if (!empty($validated['q'])) {
            $q = trim($validated['q']);
            $query->where(function ($builder) use ($q) {
                $builder
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $drivers = $query->latest('id')->get()->map(function (User $driver) {
            return [
                'id' => $driver->id,
                'name' => $driver->name,
                'first_name' => $driver->first_name,
                'last_name' => $driver->last_name,
                'phone' => $driver->phone,
                'email' => $driver->email,
                'rating' => (float) $driver->rating,
                'is_available' => (bool) $driver->is_available,
                'profile_status' => $driver->profile_status,
                'documents_status' => $driver->documents_status,
                'is_banned' => (bool) $driver->is_banned,
                'banned_at' => optional($driver->banned_at)->toIso8601String(),
                'banned_reason' => $driver->banned_reason,
                'documents' => collect($driver->documents ?? [])->map(fn ($url) => $this->absoluteUrl($url))->all(),
                'stats' => [
                    'total_jobs' => (int) $driver->driverBookings()->count(),
                    'completed_jobs' => (int) $driver->driverBookings()->where('status', 'completed')->count(),
                    'cancelled_jobs' => (int) $driver->driverBookings()->where('status', 'cancelled')->count(),
                ],
                'created_at' => optional($driver->created_at)->toIso8601String(),
            ];
        });

        return response()->json(['drivers' => $drivers]);
    }

    public function driverDetails(User $driver)
    {
        if ($driver->role !== 'driver') {
            abort(404);
        }

        $ratingRow = Booking::query()
            ->where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->whereNotNull('customer_rating')
            ->selectRaw('AVG(customer_rating) as avg_rating, COUNT(*) as total')
            ->first();

        $ratingsCount = (int) ($ratingRow?->total ?? 0);
        $ratingAverage = $ratingsCount > 0 ? round((float) ($ratingRow?->avg_rating ?? 0), 1) : 0.0;

        $reviews = Booking::query()
            ->with('customer:id,name')
            ->where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->whereNotNull('customer_rating')
            ->latest('completed_at')
            ->limit(100)
            ->get()
            ->map(fn (Booking $booking) => [
                'booking_id' => (int) $booking->id,
                'customer_name' => $booking->customer?->name ?? 'Client',
                'rating' => (int) ($booking->customer_rating ?? 0),
                'review' => (string) ($booking->customer_review ?? ''),
                'created_at' => optional($booking->completed_at ?? $booking->updated_at)->toIso8601String(),
            ])
            ->values();

        $bookings = Booking::query()
            ->with(['customer:id,name,phone', 'driver:id,name,phone'])
            ->where('driver_id', $driver->id)
            ->latest('id')
            ->limit(120)
            ->get()
            ->map(function (Booking $booking) {
                return [
                    'id' => $booking->id,
                    'status' => $booking->status,
                    'service' => $booking->service,
                    'vehicle' => $booking->vehicle,
                    'address' => $booking->address,
                    'price' => (int) $booking->price,
                    'scheduled_at' => $booking->scheduled_at,
                    'cancelled_reason' => $booking->cancelled_reason,
                    'before_photos' => collect($booking->before_photos ?? [])->map(fn ($url) => $this->absoluteUrl($url))->values()->all(),
                    'after_photos' => collect($booking->after_photos ?? [])->map(fn ($url) => $this->absoluteUrl($url))->values()->all(),
                    'customer' => [
                        'id' => $booking->customer?->id,
                        'name' => $booking->customer?->name,
                        'phone' => $booking->customer?->phone,
                    ],
                    'created_at' => optional($booking->created_at)->toIso8601String(),
                ];
            })
            ->values();

        return response()->json([
            'driver' => [
                'id' => $driver->id,
                'name' => $driver->name,
                'first_name' => $driver->first_name,
                'last_name' => $driver->last_name,
                'phone' => $driver->phone,
                'email' => $driver->email,
                'avatar_url' => $this->absoluteUrl($driver->avatar_url),
                'profile_status' => $driver->profile_status,
                'documents_status' => $driver->documents_status,
                'documents' => collect($driver->documents ?? [])->map(fn ($url) => $this->absoluteUrl($url))->all(),
                'is_available' => (bool) $driver->is_available,
                'is_banned' => (bool) $driver->is_banned,
                'banned_at' => optional($driver->banned_at)->toIso8601String(),
                'banned_reason' => $driver->banned_reason,
                'created_at' => optional($driver->created_at)->toIso8601String(),
            ],
            'metrics' => [
                'rating_average' => $ratingAverage,
                'ratings_count' => $ratingsCount,
                'total_jobs' => (int) $driver->driverBookings()->count(),
                'completed_jobs' => (int) $driver->driverBookings()->where('status', 'completed')->count(),
                'cancelled_jobs' => (int) $driver->driverBookings()->where('status', 'cancelled')->count(),
            ],
            'reviews' => $reviews,
            'bookings' => $bookings,
        ]);
    }

    public function updateDriverReview(User $driver, Request $request)
    {
        if ($driver->role !== 'driver') {
            abort(404);
        }

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
        ]);

        if ($validated['decision'] === 'approve') {
            $driver->profile_status = 'approved';
            $driver->documents_status = 'approved';
            $driver->account_step = max(8, (int) $driver->account_step);
            $documents = is_array($driver->documents) ? $driver->documents : [];
            if (!empty($documents['profile'])) {
                $driver->avatar_url = $documents['profile'];
            }
        } else {
            $driver->profile_status = 'rejected';
            $driver->documents_status = 'rejected';
        }

        $driver->save();
        event(new DriverUpdated($driver->fresh()));

        return response()->json([
            'driver' => [
                'id' => $driver->id,
                'profile_status' => $driver->profile_status,
                'documents_status' => $driver->documents_status,
            ],
        ]);
    }

    public function setDriverBan(User $driver, Request $request)
    {
        if ($driver->role !== 'driver') {
            abort(404);
        }

        $validated = $request->validate([
            'banned' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $driver->is_banned = (bool) $validated['banned'];
        if ($driver->is_banned) {
            $driver->banned_at = now();
            $driver->banned_reason = trim((string) ($validated['reason'] ?? '')) ?: 'Compte banni par l administrateur.';
            $driver->is_available = false;
        } else {
            $driver->banned_at = null;
            $driver->banned_reason = null;
        }
        $driver->save();
        event(new DriverUpdated($driver->fresh()));

        return response()->json([
            'driver' => [
                'id' => $driver->id,
                'is_banned' => (bool) $driver->is_banned,
                'banned_at' => optional($driver->banned_at)->toIso8601String(),
                'banned_reason' => $driver->banned_reason,
                'is_available' => (bool) $driver->is_available,
            ],
        ]);
    }

    public function customers(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = User::query()->where('role', 'customer');

        if (!empty($validated['q'])) {
            $q = trim($validated['q']);
            $query->where(function ($builder) use ($q) {
                $builder
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $customers = $query->latest('id')->get()->map(function (User $customer) {
            $bookings = $customer->customerBookings();
            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'wallet_balance' => (int) $customer->wallet_balance,
                'stats' => [
                    'total_orders' => (int) $bookings->count(),
                    'pending_orders' => (int) $bookings->whereIn('status', ['pending', 'accepted', 'en_route', 'arrived', 'washing'])->count(),
                    'completed_orders' => (int) $bookings->where('status', 'completed')->count(),
                ],
                'created_at' => optional($customer->created_at)->toIso8601String(),
            ];
        });

        return response()->json(['customers' => $customers]);
    }

    public function bookings(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'pending', 'accepted', 'en_route', 'arrived', 'washing', 'completed', 'cancelled'])],
        ]);

        $query = Booking::query()->with(['customer', 'driver']);

        $status = $validated['status'] ?? 'all';
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $bookings = $query->latest('id')->limit(300)->get()->map(function (Booking $booking) {
            return [
                'id' => $booking->id,
                'status' => $booking->status,
                'service' => $booking->service,
                'vehicle' => $booking->vehicle,
                'address' => $booking->address,
                'price' => (int) $booking->price,
                'scheduled_at' => $booking->scheduled_at,
                'cancelled_reason' => $booking->cancelled_reason,
                'before_photos' => collect($booking->before_photos ?? [])->map(fn ($url) => $this->absoluteUrl($url))->values()->all(),
                'after_photos' => collect($booking->after_photos ?? [])->map(fn ($url) => $this->absoluteUrl($url))->values()->all(),
                'customer' => [
                    'id' => $booking->customer?->id,
                    'name' => $booking->customer?->name,
                    'phone' => $booking->customer?->phone,
                ],
                'driver' => $booking->driver ? [
                    'id' => $booking->driver->id,
                    'name' => $booking->driver->name,
                    'phone' => $booking->driver->phone,
                ] : null,
                'created_at' => optional($booking->created_at)->toIso8601String(),
            ];
        });

        return response()->json(['bookings' => $bookings]);
    }

    public function announcements(Request $request)
    {
        $validated = $request->validate([
            'channel' => ['nullable', Rule::in(['driver_system'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);
        $channel = $validated['channel'] ?? 'driver_system';

        $announcements = AdminAnnouncement::query()
            ->where('channel', $channel)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AdminAnnouncement $item) => [
                'id' => $item->id,
                'channel' => $item->channel,
                'title' => $item->title,
                'body' => $item->body,
                'audience' => $item->audience,
                'route' => $item->route,
                'sent_count' => (int) $item->sent_count,
                'meta' => $item->meta ?? [],
                'created_at' => optional($item->created_at)->toIso8601String(),
            ])
            ->values();

        return response()->json(['announcements' => $announcements]);
    }

    public function sendDriverAnnouncement(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:2000'],
            'audience' => ['nullable', Rule::in(['all', 'approved', 'pending', 'rejected'])],
            'driver_ids' => ['nullable', 'array'],
            'driver_ids.*' => ['integer', 'exists:users,id'],
            'route' => ['nullable', 'string', 'max:255'],
        ]);

        $audience = $validated['audience'] ?? 'all';
        $query = User::query()->where('role', 'driver');

        if (!empty($validated['driver_ids'])) {
            $query->whereIn('id', $validated['driver_ids']);
        } elseif ($audience !== 'all') {
            $query->where('profile_status', $audience);
        }

        $drivers = $query->get();
        $count = 0;
        foreach ($drivers as $driver) {
            $this->notificationService->createNotification(
                $driver,
                'system',
                $validated['title'],
                $validated['body'],
                [
                    'route' => $validated['route'] ?? '/notifications',
                    'audience' => $audience,
                ]
            );
            $count++;
        }

        $announcement = AdminAnnouncement::query()->create([
            'channel' => 'driver_system',
            'title' => $validated['title'],
            'body' => $validated['body'],
            'audience' => $audience,
            'route' => $validated['route'] ?? '/notifications',
            'sent_count' => $count,
            'meta' => [
                'driver_ids' => !empty($validated['driver_ids']) ? array_values($validated['driver_ids']) : [],
            ],
        ]);
        event(new AnnouncementCreated($announcement->fresh()));

        return response()->json([
            'ok' => true,
            'sent' => $count,
            'announcement' => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'audience' => $announcement->audience,
                'route' => $announcement->route,
                'sent_count' => (int) $announcement->sent_count,
                'created_at' => optional($announcement->created_at)->toIso8601String(),
            ],
        ]);
    }

    private function absoluteUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return url($path);
    }
}

