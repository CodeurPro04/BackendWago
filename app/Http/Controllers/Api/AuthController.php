<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function mobileLogin(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'role' => ['required', 'in:customer,driver'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $normalizedPhone = preg_replace('/\s+/', '', $validated['phone']);
        $email = strtolower($validated['role']).'.'.preg_replace('/\D+/', '', $normalizedPhone).'@ziwago.local';

        $user = User::query()->firstOrCreate(
            [
                'phone' => $normalizedPhone,
                'role' => $validated['role'],
            ],
            [
                'name' => $validated['name'] ?? ($validated['role'] === 'driver' ? 'Laveur' : 'Client'),
                'email' => $email,
                'password' => Str::password(32),
                'wallet_balance' => $validated['role'] === 'customer' ? 20000 : 0,
                'is_available' => $validated['role'] === 'driver',
                'first_name' => $validated['name'] ?? ($validated['role'] === 'driver' ? 'Laveur' : 'Client'),
                'membership' => 'Standard',
                'rating' => 4.80,
                'profile_status' => $validated['role'] === 'customer' ? 'approved' : 'pending',
                'account_step' => 0,
            ]
        );

        if (!empty($validated['name']) && $user->name !== $validated['name']) {
            $user->name = $validated['name'];
            $user->save();
        }

        // Customer accounts are always auto-approved and never require docs validation.
        if ($user->role === 'customer') {
            $user->profile_status = 'approved';
            $user->documents_status = 'approved';
            $user->save();
        }

        return response()->json([
            'user' => $this->serializeUser($user),
        ]);
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
            'rating' => $user->rating,
            'profile_status' => $user->profile_status,
            'account_step' => $user->account_step,
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
