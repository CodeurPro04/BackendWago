<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(User $user)
    {
        return response()->json([
            'user' => $this->serializeUser($user),
            'stats' => $this->statsFor($user),
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

        return response()->json([
            'user' => $this->serializeUser($user->fresh()),
            'stats' => $this->statsFor($user),
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

        return response()->json([
            'user' => $this->serializeUser($driver->fresh()),
            'stats' => $this->statsFor($driver),
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

        return response()->json([
            'user' => $this->serializeUser($user->fresh()),
            'stats' => $this->statsFor($user),
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
        $driver->documents_status = 'pending';
        $driver->save();

        return response()->json([
            'user' => $this->serializeUser($driver->fresh()),
            'stats' => $this->statsFor($driver),
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

        return response()->json([
            'user' => $this->serializeUser($driver->fresh()),
            'stats' => $this->statsFor($driver),
        ]);
    }

    private function statsFor(User $user): array
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
        return [
            'total_jobs' => $jobs->count(),
            'completed_jobs' => (int) $jobs->where('status', 'completed')->count(),
            'cashout_balance' => (int) round($jobs->where('status', 'completed')->sum('price') * 0.8),
        ];
    }

    private function serializeUser(User $user): array
    {
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
            'avatar_url' => $this->absoluteUrl($user->avatar_url),
            'membership' => $user->membership,
            'rating' => (float) $user->rating,
            'profile_status' => $user->profile_status,
            'account_step' => (int) $user->account_step,
            'documents' => collect($user->documents ?? [])->map(fn ($url) => $this->absoluteUrl($url))->all(),
            'documents_status' => $user->documents_status,
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
}
