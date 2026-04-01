<?php

namespace App\Http\Controllers\Api;

use App\Events\BookingUpdated;
use App\Events\DriverUpdated;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:users,id'],
            'service' => ['required', 'string', 'max:255'],
            'vehicle' => ['required', 'string', 'max:120'],
            'wash_type_key' => ['required', Rule::in(['exterior', 'interior', 'complete'])],
            'address' => ['required', 'string', 'max:500'],
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
            'price' => ['required', 'integer', 'min:1000'],
            'scheduled_at' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $customer = User::query()->where('id', $validated['customer_id'])->where('role', 'customer')->firstOrFail();
        if ($customer->wallet_balance < (int) $validated['price']) {
            return response()->json(['message' => 'Solde insuffisant.'], 422);
        }

        $scheduledAt = null;
        if (!empty($validated['scheduled_at'])) {
            try {
                $scheduledAt = Carbon::parse($validated['scheduled_at']);
            } catch (\Throwable $exception) {
                return response()->json(['message' => 'Date de programmation invalide.'], 422);
            }

            if ($scheduledAt->lt(Carbon::now()->subMinutes(5))) {
                return response()->json(['message' => 'La date de programmation est deja passee.'], 422);
            }
        }

        $booking = Booking::query()->create([
            ...$validated,
            'scheduled_at' => $scheduledAt?->toIso8601String(),
            'customer_phone' => $customer->phone,
            'status' => 'pending',
        ]);
        $customer->wallet_balance = max(0, (int) $customer->wallet_balance - (int) $validated['price']);
        $customer->save();
        $this->dispatchSafeEvent(new BookingUpdated($booking->fresh()));

        return response()->json([
            'booking' => $this->serializeBooking($booking->load(['customer', 'driver'])),
            'customer_wallet_balance' => $customer->wallet_balance,
        ], 201);
    }

    public function show(Booking $booking)
    {
        return response()->json([
            'booking' => $this->serializeBooking($booking->load(['customer', 'driver'])),
        ]);
    }

    public function customerBookings(User $customer)
    {
        if ($customer->role !== 'customer') {
            abort(404);
        }

        $bookings = Booking::query()
            ->where('customer_id', $customer->id)
            ->with(['customer', 'driver'])
            ->latest('id')
            ->get()
            ->map(fn (Booking $booking) => $this->serializeBooking($booking));

        return response()->json(['bookings' => $bookings]);
    }

    public function cancel(Booking $booking, Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $customer = User::query()
            ->where('id', $validated['customer_id'])
            ->where('role', 'customer')
            ->first();
        if (!$customer) {
            return response()->json(['message' => 'Client non valide.'], 403);
        }

        if ((int) $booking->customer_id !== (int) $customer->id) {
            return response()->json(['message' => 'Reservation non autorisee pour ce client.'], 403);
        }

        $cancellableStatuses = ['pending', 'accepted', 'en_route'];
        if (!in_array($booking->status, $cancellableStatuses, true)) {
            return response()->json([
                'message' => 'Impossible d annuler: la mission a deja demarre ou est terminee.',
            ], 422);
        }

        $booking->status = 'cancelled';
        $booking->cancelled_reason = $validated['reason'] ?? 'cancelled_by_customer';
        $booking->save();
        $this->dispatchSafeEvent(new BookingUpdated($booking->fresh()));

        return response()->json([
            'booking' => $this->serializeBooking($booking->load(['customer', 'driver'])),
        ]);
    }

    public function rate(Booking $booking, Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:users,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review' => ['nullable', 'string', 'max:1000'],
        ]);

        if ((int) $booking->customer_id !== (int) $validated['customer_id']) {
            return response()->json(['message' => 'Reservation non autorisee pour ce client.'], 403);
        }

        if ($booking->status !== 'completed') {
            return response()->json(['message' => 'La note est disponible apres completion.'], 422);
        }
        if ($booking->customer_rating !== null) {
            return response()->json(['message' => 'Cette mission a deja ete notee.'], 422);
        }

        $booking->customer_rating = (int) $validated['rating'];
        $booking->customer_review = $validated['review'] ?? null;
        $booking->save();
        $driverRatingMeta = null;
        if ($booking->driver) {
            $driverRatingMeta = $this->syncDriverRating($booking->driver);
            $this->dispatchSafeEvent(new DriverUpdated($booking->driver->fresh()));
        }
        $this->dispatchSafeEvent(new BookingUpdated($booking->fresh()));

        return response()->json([
            'booking' => $this->serializeBooking($booking->load(['customer', 'driver']), $driverRatingMeta),
        ]);
    }

    public function uploadMedia(Booking $booking, Request $request)
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:users,id'],
            'stage' => ['required', Rule::in(['before', 'after'])],
            'photo' => ['required', 'file', 'image', 'max:5120'],
        ]);

        if ((int) $booking->driver_id !== (int) $validated['driver_id']) {
            return response()->json(['message' => 'Mission non assignee a ce laveur.'], 403);
        }

        $path = $request->file('photo')->store('booking-media', 'public');
        $url = Storage::url($path);
        $column = $validated['stage'] === 'before' ? 'before_photos' : 'after_photos';
        $current = $booking->{$column} ?? [];
        $current[] = $url;
        $booking->{$column} = $current;
        $booking->save();
        $this->dispatchSafeEvent(new BookingUpdated($booking->fresh()));

        return response()->json([
            'booking' => $this->serializeBooking($booking->load(['customer', 'driver'])),
            'uploaded_url' => $url,
        ]);
    }

    private function serializeBooking(Booking $booking, ?array $driverRatingMeta = null): array
    {
        $ratingMeta = null;
        if ($booking->driver) {
            $ratingMeta = $driverRatingMeta ?? $this->driverRatingSummary($booking->driver);
        }

        return [
            'id' => $booking->id,
            'status' => $booking->status,
            'service' => $booking->service,
            'vehicle' => $booking->vehicle,
            'wash_type_key' => $booking->wash_type_key,
            'address' => $booking->address,
            'latitude' => $booking->latitude,
            'longitude' => $booking->longitude,
            'price' => $booking->price,
            'scheduled_at' => $booking->scheduled_at,
            'customer_rating' => $booking->customer_rating,
            'customer_review' => $booking->customer_review,
            'before_photos' => $booking->before_photos ?? [],
            'after_photos' => $booking->after_photos ?? [],
            'cancelled_reason' => $booking->cancelled_reason,
            'created_at' => optional($booking->created_at)->toIso8601String(),
            'customer' => [
                'id' => $booking->customer?->id,
                'name' => $booking->customer?->name,
                'phone' => $booking->customer?->phone ?? $booking->customer_phone,
            ],
            'driver' => $booking->driver ? [
                'id' => $booking->driver->id,
                'name' => $booking->driver->name,
                'phone' => $booking->driver->phone,
                'rating' => $ratingMeta['average'] ?? (float) ($booking->driver->rating ?? 4.8),
                'reviews_count' => $ratingMeta['count'] ?? 0,
                'avatar_url' => $this->resolvedAvatarUrl($booking->driver),
            ] : null,
        ];
    }

    private function syncDriverRating(User $driver): array
    {
        $summary = $this->driverRatingSummary($driver);
        $driver->rating = $summary['average'];
        $driver->save();

        return $summary;
    }

    private function driverRatingSummary(User $driver): array
    {
        $row = Booking::query()
            ->where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->whereNotNull('customer_rating')
            ->selectRaw('AVG(customer_rating) as avg_rating, COUNT(*) as total')
            ->first();

        $count = (int) ($row?->total ?? 0);
        $average = $count > 0
            ? round((float) ($row?->avg_rating ?? 0), 1)
            : (float) ($driver->rating ?? 4.8);

        return [
            'average' => $average,
            'count' => $count,
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
