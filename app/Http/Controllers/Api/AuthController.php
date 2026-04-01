<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const OTP_TTL_SECONDS = 300;
    private const OTP_MAX_VERIFY_ATTEMPTS = 5;
    private const OTP_MAX_SEND_PER_MINUTE = 5;
    private const OAUTH_CODE_TTL_SECONDS = 300;

    public function mobileLogin(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'role' => ['required', 'in:customer,driver'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $normalizedPhone = $this->normalizePhone($validated['phone']);
        $user = $this->findOrCreatePhoneUser(
            $normalizedPhone,
            $validated['role'],
            $validated['name'] ?? null
        );
        if ($blocked = $this->blockedBanResponse($user)) {
            return $blocked;
        }

        return response()->json([
            'user' => $this->serializeUser($user),
            'token' => $this->issueToken(),
            'provider' => 'phone',
            'is_new_user' => false,
        ]);
    }

    public function sendOtp(Request $request)
    {
        $validated = $request->validate([
            'country_code' => ['required', 'regex:/^\+[0-9]{1,4}$/'],
            'phone' => ['required', 'string', 'min:6', 'max:15'],
        ]);

        $countryCode = $validated['country_code'];
        $phoneDigits = $this->normalizeDigits($validated['phone']);
        $sendThrottleKey = "auth:otp:send:{$countryCode}:{$phoneDigits}";

        $sendCount = (int) Cache::get($sendThrottleKey, 0);
        if ($sendCount >= self::OTP_MAX_SEND_PER_MINUTE) {
            return response()->json([
                'message' => 'Trop de demandes de code. Reessayez plus tard.',
            ], 429);
        }

        Cache::put($sendThrottleKey, $sendCount + 1, now()->addMinute());

        $code = (string) random_int(1000, 9999);
        $otpKey = $this->otpCacheKey($countryCode, $phoneDigits);

        Cache::put($otpKey, [
            'hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addSeconds(self::OTP_TTL_SECONDS)->toIso8601String(),
        ], now()->addSeconds(self::OTP_TTL_SECONDS));

        Log::info('ZIWAGO OTP generated', [
            'phone' => "{$countryCode}{$phoneDigits}",
            'code' => $code,
        ]);

        $response = [
            'success' => true,
            'ttl' => self::OTP_TTL_SECONDS,
            'message' => 'Code de verification envoye.',
        ];

        if ((bool) config('app.debug')) {
            $response['debug_code'] = $code;
        }

        return response()->json($response);
    }

    public function verifyOtp(Request $request)
    {
        $validated = $request->validate([
            'country_code' => ['required', 'regex:/^\+[0-9]{1,4}$/'],
            'phone' => ['required', 'string', 'min:6', 'max:15'],
            'code' => ['required', 'digits:4'],
            'role' => ['nullable', 'in:customer,driver'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $countryCode = $validated['country_code'];
        $phoneDigits = $this->normalizeDigits($validated['phone']);
        $otpKey = $this->otpCacheKey($countryCode, $phoneDigits);
        $otpData = Cache::get($otpKey);

        if (!$otpData || !is_array($otpData)) {
            throw ValidationException::withMessages([
                'code' => ['Code invalide ou expire.'],
            ]);
        }

        $attempts = (int) ($otpData['attempts'] ?? 0);
        if ($attempts >= self::OTP_MAX_VERIFY_ATTEMPTS) {
            Cache::forget($otpKey);
            return response()->json([
                'message' => 'Trop de tentatives. Veuillez redemander un code.',
            ], 429);
        }

        $expiresAt = isset($otpData['expires_at']) ? Carbon::parse($otpData['expires_at']) : null;
        if (!$expiresAt || $expiresAt->isPast()) {
            Cache::forget($otpKey);
            throw ValidationException::withMessages([
                'code' => ['Code expire. Veuillez redemander un code.'],
            ]);
        }

        $isValid = Hash::check((string) $validated['code'], (string) ($otpData['hash'] ?? ''));
        if (!$isValid) {
            $otpData['attempts'] = $attempts + 1;
            Cache::put($otpKey, $otpData, $expiresAt);

            throw ValidationException::withMessages([
                'code' => ['Code incorrect.'],
            ]);
        }

        Cache::forget($otpKey);

        $role = $validated['role'] ?? 'customer';
        $fullPhone = $this->normalizePhone("{$countryCode}{$phoneDigits}");
        $user = $this->findOrCreatePhoneUser($fullPhone, $role, $validated['name'] ?? null);
        if ($blocked = $this->blockedBanResponse($user)) {
            return $blocked;
        }

        return response()->json([
            'user' => $this->serializeUser($user),
            'token' => $this->issueToken(),
            'provider' => 'phone',
            'is_new_user' => false,
        ]);
    }

    public function registerWithEmail(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['nullable', 'in:customer,driver'],
        ]);

        $role = $validated['role'] ?? 'customer';
        $phoneDigits = $this->normalizeDigits((string) ($validated['phone'] ?? ''));
        if ($role === 'driver') {
            if ($phoneDigits === '') {
                throw ValidationException::withMessages([
                    'phone' => ['Le numero de telephone est obligatoire pour les laveurs.'],
                ]);
            }
            if (!preg_match('/^(01|05|07)[0-9]{8}$/', $phoneDigits)) {
                throw ValidationException::withMessages([
                    'phone' => ['Numero ivoirien invalide. Utilisez 10 chiffres commencant par 01, 05 ou 07.'],
                ]);
            }
        }
        $email = strtolower(trim($validated['email']));
        $name = trim($validated['first_name'].' '.$validated['last_name']);
        $normalizedPhone = $phoneDigits !== '' ? $this->normalizePhone('+225'.$phoneDigits) : null;

        if ($normalizedPhone && User::query()->where('phone', $normalizedPhone)->exists()) {
            throw ValidationException::withMessages([
                'phone' => ['Ce numero de telephone est deja utilise.'],
            ]);
        }

        $user = User::query()->create([
            'name' => $name,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $email,
            'phone' => $normalizedPhone,
            'password' => $validated['password'],
            'role' => $role,
            'wallet_balance' => $role === 'customer' ? 20000 : 0,
            'is_available' => $role === 'driver',
            'membership' => 'Standard',
            'rating' => $role === 'driver' ? 0 : 4.80,
            'profile_status' => $role === 'customer' ? 'approved' : 'pending',
            'documents_status' => $role === 'customer' ? 'approved' : 'pending',
            'account_step' => 0,
        ]);
        if ($blocked = $this->blockedBanResponse($user)) {
            return $blocked;
        }

        return response()->json([
            'user' => $this->serializeUser($user),
            'token' => $this->issueToken(),
            'provider' => 'email',
            'is_new_user' => true,
        ]);
    }

    public function loginWithEmail(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'role' => ['nullable', 'in:customer,driver'],
        ]);

        $role = $validated['role'] ?? 'customer';
        $email = strtolower(trim($validated['email']));

        $user = User::query()
            ->where('email', $email)
            ->where('role', $role)
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Identifiants invalides.',
            ], 401);
        }
        if ($blocked = $this->blockedBanResponse($user)) {
            return $blocked;
        }

        return response()->json([
            'user' => $this->serializeUser($user),
            'token' => $this->issueToken(),
            'provider' => 'email',
            'is_new_user' => false,
        ]);
    }

    public function oauthStart(Request $request)
    {
        $validated = $request->validate([
            'provider' => ['required', 'in:google,apple'],
            'redirect_uri' => ['required', 'url', 'max:1000'],
            'state' => ['required', 'string', 'min:8', 'max:128'],
            'role' => ['nullable', 'in:customer,driver'],
            'platform' => ['nullable', 'string', 'max:30'],
        ]);

        $provider = $validated['provider'];
        $state = $validated['state'];
        $redirectUri = $validated['redirect_uri'];
        $role = $validated['role'] ?? 'customer';

        // Local OAuth bridge: generate one-time code and bounce back to mobile redirect URI.
        $oneTimeCode = Str::random(64);
        $externalId = hash('sha256', implode('|', [
            $provider,
            (string) $request->ip(),
            (string) $request->userAgent(),
            config('app.key'),
        ]));

        Cache::put($this->oauthCodeKey($oneTimeCode), [
            'provider' => $provider,
            'state' => $state,
            'redirect_uri' => $redirectUri,
            'role' => $role,
            'external_id' => $externalId,
        ], now()->addSeconds(self::OAUTH_CODE_TTL_SECONDS));

        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        $target = $redirectUri.$separator.http_build_query([
            'code' => $oneTimeCode,
            'state' => $state,
        ]);

        return redirect()->away($target);
    }

    public function oauthMobileComplete(Request $request)
    {
        $validated = $request->validate([
            'provider' => ['required', 'in:google,apple'],
            'code' => ['nullable', 'string', 'max:255'],
            'id_token' => ['nullable', 'string', 'max:4096'],
            'access_token' => ['nullable', 'string', 'max:4096'],
            'redirect_uri' => ['required', 'url', 'max:1000'],
            'state' => ['nullable', 'string', 'max:128'],
        ]);

        $provider = $validated['provider'];
        $externalId = null;
        $role = 'customer';

        if (!empty($validated['code'])) {
            $oauthKey = $this->oauthCodeKey($validated['code']);
            $oauthData = Cache::pull($oauthKey);

            if (!$oauthData || !is_array($oauthData)) {
                return response()->json(['message' => 'Code OAuth invalide ou expire.'], 422);
            }

            if (($oauthData['provider'] ?? null) !== $provider) {
                return response()->json(['message' => 'Provider invalide.'], 422);
            }

            if (($oauthData['redirect_uri'] ?? null) !== $validated['redirect_uri']) {
                return response()->json(['message' => 'Redirect URI invalide.'], 422);
            }

            if (!empty($validated['state']) && ($oauthData['state'] ?? null) !== $validated['state']) {
                return response()->json(['message' => 'Etat OAuth invalide.'], 422);
            }

            $externalId = (string) ($oauthData['external_id'] ?? '');
            $role = (string) ($oauthData['role'] ?? 'customer');
        } else {
            $tokenSource = $validated['id_token'] ?? $validated['access_token'] ?? null;
            if (!$tokenSource) {
                return response()->json(['message' => 'Token OAuth manquant.'], 422);
            }
            $externalId = hash('sha256', $provider.'|'.$tokenSource);
        }

        if (empty($externalId)) {
            return response()->json(['message' => 'Identite OAuth invalide.'], 422);
        }

        $email = sprintf('%s.%s@ziwago.local', $provider, substr($externalId, 0, 24));
        $name = ucfirst($provider).' User';

        $user = User::query()->firstOrCreate(
            [
                'email' => $email,
            ],
            [
                'name' => $name,
                'first_name' => ucfirst($provider),
                'last_name' => 'User',
                'password' => Str::password(40),
                'role' => in_array($role, ['customer', 'driver'], true) ? $role : 'customer',
                'wallet_balance' => $role === 'customer' ? 20000 : 0,
                'is_available' => $role === 'driver',
                'membership' => 'Standard',
                'rating' => $role === 'driver' ? 0 : 4.80,
                'profile_status' => $role === 'customer' ? 'approved' : 'pending',
                'documents_status' => $role === 'customer' ? 'approved' : 'pending',
                'account_step' => 0,
            ]
        );

        if ($user->role === 'customer') {
            $user->profile_status = 'approved';
            $user->documents_status = 'approved';
            $user->save();
        }
        if ($blocked = $this->blockedBanResponse($user)) {
            return $blocked;
        }

        return response()->json([
            'user' => $this->serializeUser($user),
            'token' => $this->issueToken(),
            'provider' => $provider,
            'is_new_user' => false,
        ]);
    }

    private function findOrCreatePhoneUser(string $normalizedPhone, string $role, ?string $name = null): User
    {
        $email = strtolower($role).'.'.preg_replace('/\D+/', '', $normalizedPhone).'@ziwago.local';

        $user = User::query()->firstOrCreate(
            [
                'phone' => $normalizedPhone,
                'role' => $role,
            ],
            [
                'name' => $name ?: ($role === 'driver' ? 'Laveur' : 'Client'),
                'email' => $email,
                'password' => Str::password(32),
                'wallet_balance' => $role === 'customer' ? 20000 : 0,
                'is_available' => $role === 'driver',
                'first_name' => $name ?: ($role === 'driver' ? 'Laveur' : 'Client'),
                'membership' => 'Standard',
                'rating' => $role === 'driver' ? 0 : 4.80,
                'profile_status' => $role === 'customer' ? 'approved' : 'pending',
                'documents_status' => $role === 'customer' ? 'approved' : 'pending',
                'account_step' => 0,
            ]
        );

        if (!empty($name) && $user->name !== $name) {
            $user->name = $name;
            $user->first_name = $name;
            $user->save();
        }

        if ($user->role === 'customer') {
            $user->profile_status = 'approved';
            $user->documents_status = 'approved';
            $user->save();
        }

        return $user;
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
            'avatar_url' => $this->resolvedAvatarUrl($user),
            'membership' => $user->membership,
            'rating' => $user->rating,
            'profile_status' => $user->profile_status,
            'account_step' => $user->account_step,
            'documents' => collect($user->documents ?? [])->map(fn ($url) => $this->absoluteUrl($url))->all(),
            'documents_status' => $user->documents_status,
            'is_banned' => (bool) $user->is_banned,
            'banned_at' => optional($user->banned_at)->toIso8601String(),
            'banned_reason' => $user->banned_reason,
        ];
    }

    private function issueToken(): string
    {
        return Str::random(80);
    }

    private function normalizePhone(string $phone): string
    {
        $normalized = preg_replace('/\s+/', '', trim($phone));

        if (str_starts_with($normalized, '+')) {
            return '+'.$this->normalizeDigits(substr($normalized, 1));
        }

        return '+'.$this->normalizeDigits($normalized);
    }

    private function normalizeDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function otpCacheKey(string $countryCode, string $phoneDigits): string
    {
        return 'auth:otp:verify:'.$countryCode.':'.$phoneDigits;
    }

    private function oauthCodeKey(string $code): string
    {
        return 'auth:oauth:code:'.$code;
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

    private function blockedBanResponse(User $user)
    {
        if (!$user->is_banned) {
            return null;
        }

        return response()->json([
            'message' => 'Ce compte laveur est banni. Contactez l administrateur.',
            'code' => 'ACCOUNT_BANNED',
            'banned_reason' => $user->banned_reason,
        ], 403);
    }
}
