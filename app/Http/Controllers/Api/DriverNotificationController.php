<?php

namespace App\Http\Controllers\Api;

use App\Events\DriverInboxUpdated;
use App\Http\Controllers\Controller;
use App\Models\DriverNotification;
use App\Models\User;
use App\Services\DriverNotificationService;
use Illuminate\Http\Request;

class DriverNotificationController extends Controller
{
    public function __construct(private readonly DriverNotificationService $notificationService)
    {
    }

    public function index(User $driver)
    {
        $this->assertDriver($driver);

        $notifications = $driver->driverNotifications()
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (DriverNotification $notification) => $this->serializeNotification($notification))
            ->values();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => (int) $driver->driverNotifications()->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(User $driver, DriverNotification $notification)
    {
        $this->assertDriver($driver);
        $this->assertNotificationOwner($driver, $notification);

        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
            event(new DriverInboxUpdated($driver, 'notification_read'));
        }

        return response()->json([
            'notification' => $this->serializeNotification($notification->fresh()),
        ]);
    }

    public function markAllRead(User $driver)
    {
        $this->assertDriver($driver);
        $driver->driverNotifications()->whereNull('read_at')->update(['read_at' => now()]);
        event(new DriverInboxUpdated($driver, 'notifications_all_read'));

        return response()->json(['ok' => true]);
    }

    public function clear(User $driver)
    {
        $this->assertDriver($driver);
        $driver->driverNotifications()->delete();
        event(new DriverInboxUpdated($driver, 'notifications_cleared'));

        return response()->json(['ok' => true]);
    }

    public function walletTransactions(User $driver)
    {
        $this->assertDriver($driver);
        $transactions = $driver->walletTransactions()
            ->latest('id')
            ->limit(300)
            ->get()
            ->map(fn ($tx) => $this->serializeTransaction($tx))
            ->values();

        return response()->json([
            'balance' => (int) $driver->walletTransactions()->sum('amount'),
            'transactions' => $transactions,
        ]);
    }

    public function storeWalletTransaction(User $driver, Request $request)
    {
        $this->assertDriver($driver);
        $validated = $request->validate([
            'type' => ['required', 'in:deposit,withdrawal'],
            'amount' => ['required', 'integer', 'min:100'],
            'method' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validated['type'] === 'withdrawal') {
            $balance = (int) $driver->walletTransactions()->sum('amount');
            if ($balance < (int) $validated['amount']) {
                return response()->json(['message' => 'Solde insuffisant pour ce retrait.'], 422);
            }
        }

        $transaction = $this->notificationService->recordWalletAdjustment(
            $driver,
            $validated['type'],
            (int) $validated['amount'],
            $validated['method'] ?? null
        );

        return response()->json([
            'transaction' => $this->serializeTransaction($transaction),
            'balance' => (int) $driver->walletTransactions()->sum('amount'),
        ], 201);
    }

    public function registerDevice(User $driver, Request $request)
    {
        $this->assertDriver($driver);
        $validated = $request->validate([
            'expo_push_token' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        if (array_key_exists('expo_push_token', $validated)) {
            $driver->expo_push_token = trim((string) ($validated['expo_push_token'] ?? '')) ?: null;
        }

        $currentVersion = trim((string) ($validated['app_version'] ?? ''));
        $latestVersion = trim((string) env('MOBILE_DRIVER_LATEST_VERSION', ''));
        $shouldNotifyUpdate = false;

        if ($currentVersion !== '') {
            $driver->app_version_last_seen = $currentVersion;
            if ($latestVersion !== '' && version_compare($currentVersion, $latestVersion, '<')) {
                $alreadyExists = $driver->driverNotifications()
                    ->where('type', 'system')
                    ->where('title', 'Mise a jour disponible')
                    ->where('body', 'like', '%'.$latestVersion.'%')
                    ->exists();
                $shouldNotifyUpdate = !$alreadyExists;
            }
        }

        $driver->save();

        if ($shouldNotifyUpdate) {
            $this->notificationService->createNotification(
                $driver,
                'system',
                'Mise a jour disponible',
                sprintf('Une nouvelle version (%s) est disponible. Mettez a jour votre application.', $latestVersion),
                ['latest_version' => $latestVersion, 'route' => '/notifications']
            );
        }

        return response()->json([
            'ok' => true,
            'latest_version' => $latestVersion !== '' ? $latestVersion : null,
        ]);
    }

    private function serializeNotification(DriverNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'data' => $notification->data ?? [],
            'read_at' => optional($notification->read_at)->toIso8601String(),
            'created_at' => optional($notification->created_at)->toIso8601String(),
        ];
    }

    private function serializeTransaction($tx): array
    {
        return [
            'id' => $tx->id,
            'type' => $tx->type,
            'amount' => (int) $tx->amount,
            'method' => $tx->method,
            'meta' => $tx->meta ?? [],
            'created_at' => optional($tx->created_at)->toIso8601String(),
        ];
    }

    private function assertDriver(User $driver): void
    {
        if ($driver->role !== 'driver') {
            abort(404);
        }
    }

    private function assertNotificationOwner(User $driver, DriverNotification $notification): void
    {
        if ((int) $notification->user_id !== (int) $driver->id) {
            abort(404);
        }
    }
}
