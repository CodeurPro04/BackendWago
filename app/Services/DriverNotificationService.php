<?php

namespace App\Services;

use App\Events\DriverInboxUpdated;
use App\Models\Booking;
use App\Models\DriverNotification;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\DispatchesSafeEvents;
use Illuminate\Support\Facades\Http;

class DriverNotificationService
{
    use DispatchesSafeEvents;

    private const COMMISSION_RATE = 0.2;

    public function createNotification(
        User $driver,
        string $type,
        string $title,
        string $body,
        ?array $data = null
    ): DriverNotification {
        $notification = DriverNotification::query()->create([
            'user_id' => $driver->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        $this->sendExpoPush($driver, $title, $body, $data);
        $this->dispatchSafeEvent(new DriverInboxUpdated($driver, 'notification_created'));

        return $notification;
    }

    public function recordCompletedJobEarning(User $driver, Booking $booking): void
    {
        $existing = WalletTransaction::query()
            ->where('user_id', $driver->id)
            ->where('booking_id', $booking->id)
            ->where('type', 'earning')
            ->exists();
        if ($existing) {
            return;
        }

        $gross = (int) $booking->price;
        $commission = (int) round($gross * self::COMMISSION_RATE);
        $net = max(0, $gross - $commission);

        WalletTransaction::query()->create([
            'user_id' => $driver->id,
            'booking_id' => $booking->id,
            'type' => 'earning',
            'amount' => $net,
            'method' => null,
            'meta' => [
                'gross' => $gross,
                'commission' => $commission,
                'service' => $booking->service,
            ],
        ]);

        $balance = (int) $driver->walletTransactions()->sum('amount');
        $this->createNotification(
            $driver,
            'earning',
            'Mission terminee',
            sprintf(
                '+%s F CFA credites (commission %s F CFA). Solde actuel: %s F CFA.',
                number_format($net, 0, ',', ' '),
                number_format($commission, 0, ',', ' '),
                number_format($balance, 0, ',', ' ')
            ),
            [
                'booking_id' => $booking->id,
                'amount' => $net,
                'commission' => $commission,
                'route' => '/(tabs)/wallet',
            ]
        );
    }

    public function recordWalletAdjustment(
        User $driver,
        string $type,
        int $amount,
        ?string $method = null
    ): WalletTransaction {
        $signed = $type === 'withdrawal' ? -abs($amount) : abs($amount);
        $transaction = WalletTransaction::query()->create([
            'user_id' => $driver->id,
            'booking_id' => null,
            'type' => $type,
            'amount' => $signed,
            'method' => $method,
        ]);

        $balance = (int) $driver->walletTransactions()->sum('amount');
        $title = $type === 'deposit' ? 'Depot confirme' : 'Retrait confirme';
        $body = $type === 'deposit'
            ? sprintf(
                'Votre depot de %s F CFA a ete ajoute. Solde actuel: %s F CFA.',
                number_format($amount, 0, ',', ' '),
                number_format($balance, 0, ',', ' ')
            )
            : sprintf(
                'Votre retrait de %s F CFA a ete traite. Solde actuel: %s F CFA.',
                number_format($amount, 0, ',', ' '),
                number_format($balance, 0, ',', ' ')
            );

        $this->createNotification(
            $driver,
            $type,
            $title,
            $body,
            [
                'amount' => $amount,
                'method' => $method,
                'route' => '/(tabs)/wallet',
            ]
        );
        $this->dispatchSafeEvent(new DriverInboxUpdated($driver, 'wallet_transaction_created'));

        return $transaction;
    }

    private function sendExpoPush(User $driver, string $title, string $body, ?array $data = null): void
    {
        $token = trim((string) ($driver->expo_push_token ?? ''));
        if ($token === '') {
            return;
        }

        try {
            Http::timeout(5)->post('https://exp.host/--/api/v2/push/send', [
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
                'data' => $data ?? [],
            ]);
        } catch (\Throwable $exception) {
            // Keep API response successful even if push provider fails.
        }
    }
}
