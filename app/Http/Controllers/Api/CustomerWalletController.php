<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\GeniusPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerWalletController extends Controller
{
    private const TOPUP_MIN_AMOUNT = 2000;
    private const TOPUP_MAX_AMOUNT = 100000;

    public function createWaveTopup(User $customer, Request $request, GeniusPayService $geniusPay)
    {
        if ($customer->role !== 'customer') {
            abort(404);
        }

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:' . self::TOPUP_MIN_AMOUNT, 'max:' . self::TOPUP_MAX_AMOUNT],
            'return_url' => ['nullable', 'string', 'max:2048'],
            'success_url' => ['nullable', 'string', 'max:2048'],
            'error_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $reference = 'ziwago_topup_' . $customer->id . '_' . now()->format('YmdHis') . '_' . Str::lower(Str::random(8));

        $phone = trim((string) ($customer->phone ?? ''));
        if ($phone === '') {
            return response()->json([
                'message' => 'Numero de telephone requis pour payer via Wave.',
            ], 422);
        }

        $returnUrl = $validated['return_url'] ?? null;
        $scheme = trim((string) config('services.geniuspay.app_scheme', 'ziwago'));
        $deepLinkTarget = $returnUrl ?: "{$scheme}://wallet/topup-callback";

        $appUrl = rtrim((string) config('app.url'), '/');
        $successUrl = $validated['success_url'] ?? ($appUrl . '/pay/return?' . http_build_query([
            'status' => 'success',
            'reference' => $reference,
            'target' => $deepLinkTarget,
        ]));
        $errorUrl = $validated['error_url'] ?? ($appUrl . '/pay/return?' . http_build_query([
            'status' => 'error',
            'reference' => $reference,
            'target' => $deepLinkTarget,
        ]));

        $payment = $geniusPay->createWavePayment([
            'amount' => (int) $validated['amount'],
            'currency' => 'XOF',
            'customer' => [
                'phone' => $phone,
                'name' => (string) ($customer->name ?? ''),
                'email' => (string) ($customer->email ?? ''),
            ],
            'success_url' => $successUrl,
            'error_url' => $errorUrl,
            'metadata' => [
                'reference' => $reference,
                'customer_id' => (int) $customer->id,
                'purpose' => 'wallet_topup',
                'amount_min' => self::TOPUP_MIN_AMOUNT,
                'amount_max' => self::TOPUP_MAX_AMOUNT,
            ],
        ]);

        $checkoutUrl = data_get($payment, 'checkout_url')
            ?? data_get($payment, 'checkout.link')
            ?? data_get($payment, 'checkout.url')
            ?? data_get($payment, 'checkoutLink')
            ?? data_get($payment, 'redirect_url')
            ?? data_get($payment, 'redirectUrl')
            ?? data_get($payment, 'payment_url')
            ?? data_get($payment, 'paymentUrl')
            ?? data_get($payment, 'link')
            ?? data_get($payment, 'url')
            ?? data_get($payment, 'data.checkout_url')
            ?? data_get($payment, 'data.checkout.link')
            ?? data_get($payment, 'data.checkout.url')
            ?? data_get($payment, 'data.checkoutLink')
            ?? data_get($payment, 'data.redirect_url')
            ?? data_get($payment, 'data.redirectUrl')
            ?? data_get($payment, 'data.payment_url')
            ?? data_get($payment, 'data.paymentUrl')
            ?? data_get($payment, 'data.link')
            ?? data_get($payment, 'data.url');

        if (!$checkoutUrl) {
            $providerMessage = data_get($payment, 'message')
                ?? data_get($payment, 'body.message')
                ?? data_get($payment, 'body.error')
                ?? data_get($payment, 'body.errors.0')
                ?? data_get($payment, 'body.errors.message')
                ?? data_get($payment, 'body.raw')
                ?? data_get($payment, 'data.message')
                ?? data_get($payment, 'data.error')
                ?? data_get($payment, 'error');

            return response()->json([
                'message' => $providerMessage
                    ? "GeniusPay: {$providerMessage}"
                    : 'Impossible de creer le paiement GeniusPay (checkout_url manquant).',
                'provider' => 'geniuspay',
                'status' => data_get($payment, 'status'),
                'raw' => $payment,
            ], 502);
        }

        try {
            DB::transaction(function () use ($customer, $payment, $reference, $validated) {
                WalletTransaction::query()->create([
                    'user_id' => $customer->id,
                    'booking_id' => null,
                    'type' => 'topup_pending',
                    'amount' => (int) $validated['amount'],
                    'method' => 'wave',
                    'meta' => [
                        'provider' => 'geniuspay',
                        'reference' => $reference,
                        'status' => 'pending',
                        'provider_payload' => $payment,
                    ],
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('wallet:topup db error', ['reference' => $reference, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'reference' => $reference,
            'checkout_url' => $checkoutUrl,
            'provider' => 'geniuspay',
            'raw' => $payment,
        ]);
    }

    public function createWithdrawal(User $customer, Request $request, GeniusPayService $geniusPay)
    {
        if ($customer->role !== 'customer') {
            abort(404);
        }

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:100'],
            'recipient_name' => ['required', 'string', 'max:160'],
            'recipient_phone' => ['required', 'string', 'max:30'],
            'account_number' => ['required', 'string', 'max:40'],
            'provider' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $walletId = trim((string) config('services.geniuspay.payout_wallet_id', ''));
        if ($walletId === '') {
            return response()->json([
                'message' => 'Retrait indisponible (GENIUSPAY_PAYOUT_WALLET_ID manquant).',
            ], 502);
        }

        $reference = 'ziwago_withdraw_' . $customer->id . '_' . now()->format('YmdHis') . '_' . Str::lower(Str::random(8));

        $transaction = null;
        $balanceAfter = null;

        DB::transaction(function () use ($customer, $validated, $reference, &$transaction, &$balanceAfter) {
            $user = User::query()->lockForUpdate()->findOrFail($customer->id);
            $amount = (int) $validated['amount'];
            if ($amount <= 0 || (int) $user->wallet_balance < $amount) {
                throw ValidationException::withMessages([
                    'amount' => ['Solde insuffisant.'],
                ]);
            }

            $user->wallet_balance = max(0, (int) $user->wallet_balance - $amount);
            $user->save();
            $balanceAfter = (int) $user->wallet_balance;

            $transaction = WalletTransaction::query()->create([
                'user_id' => $user->id,
                'booking_id' => null,
                'type' => 'withdraw_pending',
                'amount' => $amount,
                'method' => 'mobile_money',
                'meta' => [
                    'provider' => 'geniuspay',
                    'reference' => $reference,
                    'status' => 'pending',
                    'payout' => [
                        'recipient_name' => $validated['recipient_name'],
                        'recipient_phone' => $validated['recipient_phone'],
                        'account_number' => $validated['account_number'],
                        'provider' => $validated['provider'] ?? null,
                    ],
                ],
            ]);
        });

        $payout = $geniusPay->createMobileMoneyPayout([
            'wallet_id' => $walletId,
            'amount' => (int) $validated['amount'],
            'recipient_name' => (string) $validated['recipient_name'],
            'recipient_phone' => (string) $validated['recipient_phone'],
            'account_number' => (string) $validated['account_number'],
            'provider' => $validated['provider'] ?? null,
            'description' => $validated['description'] ?? 'Retrait portefeuille Ziwago',
            'metadata' => [
                'reference' => $reference,
                'customer_id' => (int) $customer->id,
                'purpose' => 'wallet_withdrawal',
            ],
        ]);

        $providerError = data_get($payout, 'error') ?? data_get($payout, 'body.error') ?? null;
        if ($providerError) {
            Log::warning('geniuspay:payout failed', ['reference' => $reference, 'payout' => $payout]);

            DB::transaction(function () use ($customer, $transaction, $payout) {
                if (!$transaction) return;

                $tx = WalletTransaction::query()->lockForUpdate()->find($transaction->id);
                if (!$tx) return;

                $meta = is_array($tx->meta) ? $tx->meta : [];
                $meta['status'] = 'failed';
                $meta['provider_payload'] = $payout;
                $tx->meta = $meta;
                $tx->type = 'withdraw_failed';
                $tx->save();

                $user = User::query()->lockForUpdate()->find($customer->id);
                if ($user) {
                    $user->wallet_balance = (int) $user->wallet_balance + (int) $tx->amount;
                    $user->save();
                }
            });

            $providerMessage = data_get($payout, 'body.message')
                ?? data_get($payout, 'body.error')
                ?? data_get($payout, 'message')
                ?? $providerError;

            return response()->json([
                'message' => $providerMessage ? "GeniusPay: {$providerMessage}" : 'Impossible d initier le retrait.',
                'provider' => 'geniuspay',
                'raw' => $payout,
            ], 502);
        }

        DB::transaction(function () use ($transaction, $payout) {
            if (!$transaction) return;
            $tx = WalletTransaction::query()->lockForUpdate()->find($transaction->id);
            if (!$tx) return;
            $meta = is_array($tx->meta) ? $tx->meta : [];
            $meta['provider_payload'] = $payout;
            $meta['status'] = $meta['status'] ?? 'pending';
            $tx->meta = $meta;
            $tx->save();
        });

        return response()->json([
            'reference' => $reference,
            'customer_wallet_balance' => $balanceAfter,
            'provider' => 'geniuspay',
            'raw' => $payout,
        ]);
    }
}
