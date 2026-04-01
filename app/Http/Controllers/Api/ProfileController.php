<?php

namespace App\Http\Controllers\Api;

use App\Events\DriverUpdated;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(User $user)
    {
        $driverRatingMeta = $user->role === 'driver' ? $this->driverRatingMeta($user) : null;

        return response()->json([
            'user' => $this->serializeUser($user, $driverRatingMeta),
            'stats' => $this->statsFor($user, $driverRatingMeta),
        ]);
    }

    public function update(User $user, Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
            'bio' => ['nullable', 'string', 'max:1000'],
            'avatar_url' => ['nullable', 'string', 'max:1000'],
            'is_available' => ['nullable', 'boolean'],
            'account_step' => ['nullable', 'integer', 'min:0', 'max:20'],
            'profile_status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
        ]);

        foreach ($validated as $key => $value) {
            $user->{$key} = $value;
        }

        $first = trim((string) ($user->first_name ?? ''));
        $last = trim((string) ($user->last_name ?? ''));
        if ($first !== '' || $last !== '') {
            $user->name = trim($first.' '.$last);
        }

        $user->save();
        if ($user->role === 'driver') {
            $this->dispatchSafeEvent(new DriverUpdated($user->fresh()));
        }
        $driverRatingMeta = $user->role === 'driver' ? $this->driverRatingMeta($user->fresh()) : null;

        return response()->json([
            'user' => $this->serializeUser($user->fresh(), $driverRatingMeta),
            'stats' => $this->statsFor($user, $driverRatingMeta),
        ]);
    }

    public function approveDriver(User $driver, Request $request)
    {
        if ($driver->role !== 'driver') {
            abort(404);
        }

        $validated = $request->validate([
            'approved' => ['nullable', 'boolean'],
        ]);

        $approved = $validated['approved'] ?? true;
        $driver->profile_status = $approved ? 'approved' : 'pending';
        $driver->account_step = $approved ? max(8, (int) $driver->account_step) : (int) $driver->account_step;
        $driver->save();
        $this->dispatchSafeEvent(new DriverUpdated($driver->fresh()));
        $driverRatingMeta = $this->driverRatingMeta($driver->fresh());

        return response()->json([
            'user' => $this->serializeUser($driver->fresh(), $driverRatingMeta),
            'stats' => $this->statsFor($driver, $driverRatingMeta),
        ]);
    }

    public function uploadAvatar(User $user, Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'max:5120'],
        ]);

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->avatar_url = Storage::url($path);
        $user->save();
        if ($user->role === 'driver') {
            $this->dispatchSafeEvent(new DriverUpdated($user->fresh()));
        }
        $driverRatingMeta = $user->role === 'driver' ? $this->driverRatingMeta($user->fresh()) : null;

        return response()->json([
            'user' => $this->serializeUser($user->fresh(), $driverRatingMeta),
            'stats' => $this->statsFor($user, $driverRatingMeta),
        ]);
    }

    public function uploadDocument(User $driver, Request $request)
    {
        if ($driver->role !== 'driver') {
            abort(404);
        }

        $validated = $request->validate([
            'type' => ['required', Rule::in(['id', 'profile', 'license', 'address', 'certificate'])],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $path = $request->file('file')->store('driver-documents', 'public');
        $documents = $driver->documents ?? [];
        $documents[$validated['type']] = Storage::url($path);
        $driver->documents = $documents;
        if ($validated['type'] === 'profile') {
            $driver->avatar_url = $documents[$validated['type']];
        }
        $driver->documents_status = 'pending';
        $driver->save();
        $this->dispatchSafeEvent(new DriverUpdated($driver->fresh()));
        $driverRatingMeta = $this->driverRatingMeta($driver->fresh());

        return response()->json([
            'user' => $this->serializeUser($driver->fresh(), $driverRatingMeta),
            'stats' => $this->statsFor($driver, $driverRatingMeta),
        ]);
    }

    public function submitDocuments(User $driver)
    {
        if ($driver->role !== 'driver') {
            abort(404);
        }

        $required = ['id', 'profile', 'license', 'address', 'certificate'];
        $documents = $driver->documents ?? [];
        foreach ($required as $doc) {
            if (empty($documents[$doc])) {
                return response()->json(['message' => 'Tous les documents sont requis avant soumission.'], 422);
            }
        }

        $driver->documents_status = 'submitted';
        $driver->profile_status = 'pending';
        $driver->account_step = max((int) $driver->account_step, 6);
        $driver->save();
        $this->dispatchSafeEvent(new DriverUpdated($driver->fresh()));
        $driverRatingMeta = $this->driverRatingMeta($driver->fresh());

        return response()->json([
            'user' => $this->serializeUser($driver->fresh(), $driverRatingMeta),
            'stats' => $this->statsFor($driver, $driverRatingMeta),
        ]);
    }

    private function statsFor(User $user, ?array $driverRatingMeta = null): array
    {
        if ($user->role === 'customer') {
            $bookings = $user->customerBookings()->get();
            return [
                'total_orders' => $bookings->count(),
                'total_spent' => (int) $bookings->sum('price'),
                'pending_orders' => (int) $bookings->whereIn('status', ['pending', 'accepted', 'arrived', 'washing'])->count(),
            ];
        }

        $jobs = $user->driverBookings()->get();
        $ratingMeta = $driverRatingMeta ?? $this->driverRatingMeta($user);
        return [
            'total_jobs' => $jobs->count(),
            'completed_jobs' => (int) $jobs->where('status', 'completed')->count(),
            'cashout_balance' => (int) round($jobs->where('status', 'completed')->sum('price') * 0.8),
            'rating_average' => $ratingMeta['average'],
            'ratings_count' => $ratingMeta['count'],
            'reviews_count' => $ratingMeta['reviews_count'],
            'recent_reviews' => $ratingMeta['recent_reviews'],
        ];
    }

    private function serializeUser(User $user, ?array $driverRatingMeta = null): array
    {
        $rating = (float) $user->rating;
        if ($user->role === 'driver') {
            $rating = ($driverRatingMeta ?? $this->driverRatingMeta($user))['average'];
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => $user->role,
            'wallet_balance' => $user->wallet_balance,
            'is_available' => $user->is_available,
            'bio' => $user->bio,
            'avatar_url' => $this->resolvedAvatarUrl($user),
            'membership' => $user->membership,
            'rating' => $rating,
            'profile_status' => $user->profile_status,
            'account_step' => (int) $user->account_step,
            'documents' => collect($user->documents ?? [])->map(fn ($url) => $this->absoluteUrl($url))->all(),
            'documents_status' => $user->documents_status,
        ];
    }

    private function driverRatingMeta(User $driver): array
    {
        $ratingRow = Booking::query()
            ->where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->whereNotNull('customer_rating')
            ->selectRaw('AVG(customer_rating) as avg_rating, COUNT(*) as total')
            ->first();

        $count = (int) ($ratingRow?->total ?? 0);
        $average = $count > 0
            ? round((float) ($ratingRow?->avg_rating ?? 0), 1)
            : (float) ($driver->rating ?? 4.8);

        $reviews = Booking::query()
            ->with('customer:id,name')
            ->where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->whereNotNull('customer_rating')
            ->whereNotNull('customer_review')
            ->where('customer_review', '!=', '')
            ->latest('completed_at')
            ->limit(20)
            ->get();

        return [
            'average' => $average,
            'count' => $count,
            'reviews_count' => $reviews->count(),
            'recent_reviews' => $reviews->map(fn (Booking $booking) => [
                'booking_id' => (int) $booking->id,
                'customer_name' => $booking->customer?->name ?? 'Client',
                'rating' => (int) $booking->customer_rating,
                'review' => (string) ($booking->customer_review ?? ''),
                'created_at' => optional($booking->completed_at ?? $booking->updated_at)->toIso8601String(),
            ])->values()->all(),
        ];
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

    private function resolvedAvatarUrl(User $user): ?string
    {
        $documents = is_array($user->documents) ? $user->documents : [];
        $avatarPath = $user->avatar_url ?: ($documents['profile'] ?? null);

        return $this->absoluteUrl($avatarPath);
    }
}
