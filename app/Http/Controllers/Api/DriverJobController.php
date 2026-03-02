<?php

namespace App\Http\Controllers\Api;

use App\Events\BookingUpdated;
use App\Events\DriverUpdated;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use App\Services\DriverNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DriverJobController extends Controller
{
    private const SCHEDULE_VISIBILITY_LEAD_MINUTES = 20;

    public function __construct(private readonly DriverNotificationService $notificationService)
    {
    }

    public function jobs(User $driver, Request $request)
    {
        if ($driver->role !== 'driver') {
            abort(404);
        }
        if ($driver->is_banned) {
            return response()->json([
                'message' => 'Compte laveur banni. Missions indisponibles.',
                'code' => 'ACCOUNT_BANNED',
            ], 403);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string'],
        ]);

        $query = Booking::query()
            ->with(['customer', 'driver'])
            ->where(function ($builder) use ($driver) {
                $builder->whereNull('driver_id')
                    ->orWhere('driver_id', $driver->id);
            });

        if (!$driver->is_available || $driver->profile_status !== 'approved') {
            $query->where('driver_id', $driver->id);
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $jobs = $query->latest('id')
            ->limit(100)
            ->get()
            ->filter(fn (Booking $booking) => $this->isBookingVisibleForDriver($booking, $driver->id))
            ->take(40)
            ->map(fn (Booking $booking) => $this->serializeJob($booking));

        return response()->json(['jobs' => $jobs]);
    }

    public function updateAvailability(User $driver, Request $request)
    {
        if ($driver->role !== 'driver') {
            abort(404);
        }
        if ($driver->is_banned) {
            return response()->json([
                'message' => 'Compte laveur banni. Statut indisponible.',
                'code' => 'ACCOUNT_BANNED',
            ], 403);
        }

        $validated = $request->validate([
            'is_available' => ['required', 'boolean'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        $driver->is_available = $validated['is_available'];
        $driver->latitude = $validated['latitude'] ?? $driver->latitude;
        $driver->longitude = $validated['longitude'] ?? $driver->longitude;
        $driver->save();
        event(new DriverUpdated($driver->fresh()));

        return response()->json([
            'driver' => [
                'id' => $driver->id,
                'is_available' => $driver->is_available,
                'latitude' => $driver->latitude,
                'longitude' => $driver->longitude,
            ],
        ]);
    }

    public function accept(Booking $booking, Request $request)
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $driver = User::query()->where('id', $validated['driver_id'])->where('role', 'driver')->firstOrFail();
        if ($driver->is_banned) {
            return response()->json(['message' => 'Compte laveur banni.'], 403);
        }
        if ($driver->profile_status !== 'approved') {
            return response()->json(['message' => 'Compte non valide. Validation requise avant acceptation.'], 403);
        }

        $hasActiveMission = Booking::query()
            ->where('driver_id', $driver->id)
            ->whereIn('status', ['pending', 'accepted', 'en_route', 'arrived', 'washing'])
            ->exists();
        if ($hasActiveMission) {
            return response()->json([
                'message' => 'Vous avez deja une mission en cours. Terminez-la avant d en accepter une autre.',
            ], 422);
        }

        if ($booking->status !== 'pending') {
            return response()->json(['message' => 'Cette mission n est plus disponible.'], 422);
        }

        if (!$this->canBeAcceptedNow($booking)) {
            return response()->json([
                'message' => 'Mission programmee: acceptance disponible 20 minutes avant le creneau.',
            ], 422);
        }

        $booking->driver_id = $driver->id;
        $booking->status = 'accepted';
        $booking->save();
        event(new BookingUpdated($booking->fresh()));
        event(new DriverUpdated($driver->fresh()));

        return response()->json(['job' => $this->serializeJob($booking->load(['customer', 'driver']))]);
    }

    public function decline(Booking $booking, Request $request)
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        if ($booking->status !== 'pending') {
            return response()->json(['message' => 'Cette mission n est plus en attente.'], 422);
        }

        $driverId = (int) $validated['driver_id'];
        if ($booking->driver_id !== null && (int) $booking->driver_id !== $driverId) {
            return response()->json(['message' => 'Cette mission est assignee a un autre laveur.'], 403);
        }

        $booking->status = 'pending';
        $booking->save();
        event(new BookingUpdated($booking->fresh()));

        return response()->json(['job' => $this->serializeJob($booking->load(['customer', 'driver']))]);
    }

    public function transition(Booking $booking, Request $request)
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:users,id'],
            'action' => ['required', Rule::in(['arrive', 'start', 'complete', 'cancel'])],
        ]);

        if ((int) $booking->driver_id !== (int) $validated['driver_id']) {
            return response()->json(['message' => 'Mission non assignee a ce laveur.'], 403);
        }
        $driver = User::query()->where('id', (int) $validated['driver_id'])->where('role', 'driver')->firstOrFail();
        if ($driver->is_banned) {
            return response()->json(['message' => 'Compte laveur banni.'], 403);
        }

        $now = Carbon::now();
        $action = $validated['action'];

        if ($action === 'cancel') {
            return response()->json([
                'message' => 'Seul le client peut annuler la mission.',
            ], 403);
        }

        if ($action === 'arrive' && in_array($booking->status, ['accepted', 'en_route'], true)) {
            $booking->status = 'arrived';
            $booking->driver_arrived_at = $now;
        }

        if ($action === 'start' && $booking->status === 'arrived') {
            $beforePhotos = $booking->before_photos ?? [];
            if (count($beforePhotos) < 6) {
                return response()->json(['message' => 'Ajoutez au moins 6 photos avant de demarrer le lavage.'], 422);
            }
            $booking->status = 'washing';
            $booking->wash_started_at = $now;
        }

        if ($action === 'complete' && in_array($booking->status, ['washing', 'arrived'], true)) {
            $afterPhotos = $booking->after_photos ?? [];
            if (count($afterPhotos) < 6) {
                return response()->json(['message' => 'Ajoutez au moins 6 photos apres lavage avant de terminer.'], 422);
            }
            $booking->status = 'completed';
            $booking->completed_at = $now;
            $this->notificationService->recordCompletedJobEarning($driver, $booking);
        }

        $booking->save();
        event(new BookingUpdated($booking->fresh()));
        event(new DriverUpdated($driver->fresh()));

        return response()->json(['job' => $this->serializeJob($booking->load(['customer', 'driver']))]);
    }

    private function serializeJob(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'status' => $booking->status,
            'customer_name' => $booking->customer?->name ?? 'Client',
            'customer_phone' => $booking->customer?->phone ?? $booking->customer_phone,
            'customer_avatar_url' => $this->absoluteUrl($booking->customer?->avatar_url),
            'service' => $booking->service,
            'vehicle' => $booking->vehicle,
            'address' => $booking->address,
            'latitude' => $booking->latitude,
            'longitude' => $booking->longitude,
            'price' => $booking->price,
            'scheduled_at' => $booking->scheduled_at,
            'before_photos' => $booking->before_photos ?? [],
            'after_photos' => $booking->after_photos ?? [],
            'customer_rating' => $booking->customer_rating,
            'customer_review' => $booking->customer_review,
            'cancelled_reason' => $booking->cancelled_reason,
            'created_at' => optional($booking->created_at)->toIso8601String(),
            'driver_id' => $booking->driver_id,
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

    private function isBookingVisibleForDriver(Booking $booking, int $driverId): bool
    {
        if ((int) $booking->driver_id === $driverId) {
            return true;
        }

        if ($booking->driver_id !== null) {
            return false;
        }

        if ($booking->status !== 'pending') {
            return false;
        }

        return $this->canBeAcceptedNow($booking);
    }

    private function canBeAcceptedNow(Booking $booking): bool
    {
        $scheduledAt = $this->parseScheduledAt($booking->scheduled_at);
        if (!$scheduledAt) {
            return true;
        }

        return $scheduledAt->lessThanOrEqualTo(Carbon::now()->addMinutes(self::SCHEDULE_VISIBILITY_LEAD_MINUTES));
    }

    private function parseScheduledAt(?string $raw): ?Carbon
    {
        if (empty($raw)) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
