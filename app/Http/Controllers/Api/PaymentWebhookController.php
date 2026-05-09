<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function geniusPay(Request $request)
    {
        $secret = trim((string) env('GENIUSPAY_WEBHOOK_SECRET', ''));
        if ($secret !== '') {
            $signatureHeader = $request->header('X-GeniusPay-Signature')
                ?? $request->header('X-Signature')
                ?? $request->header('X-Webhook-Signature');

            if (!$signatureHeader) {
                return response()->json(['message' => 'Signature manquante.'], 401);
            }

            $expected = hash_hmac('sha256', $request->getContent(), $secret);
            if (!hash_equals($expected, (string) $signatureHeader)) {
                return response()->json(['message' => 'Signature invalide.'], 401);
            }
        }

        $payload = $request->all();
        Log::info('geniuspay:webhook received', [
            'headers' => [
                'x-geniuspay-signature' => $request->header('X-GeniusPay-Signature'),
                'x-signature' => $request->header('X-Signature'),
                'x-webhook-signature' => $request->header('X-Webhook-Signature'),
            ],
            'payload' => $payload,
        ]);
        $reference =
            data_get($payload, 'metadata.reference')
            ?? data_get($payload, 'data.metadata.reference')
            ?? data_get($payload, 'reference')
            ?? data_get($payload, 'data.reference');

        $statusRaw =
            data_get($payload, 'status')
            ?? data_get($payload, 'data.status')
            ?? data_get($payload, 'state')
            ?? data_get($payload, 'data.state');

        $status = is_string($statusRaw) ? strtolower(trim($statusRaw)) : null;
        $isSuccess = $status && in_array($status, ['success', 'succeeded', 'paid', 'completed', 'complete'], true);
        $isFailed = $status && in_array($status, ['failed', 'error', 'cancelled', 'canceled'], true);

        if (!$reference) {
            Log::warning('geniuspay:webhook missing reference', ['payload' => $payload]);
            return response()->json(['ok' => true]);
        }

        /** @var WalletTransaction|null $transaction */
        $transaction = WalletTransaction::query()
            ->where('method', 'wave')
            ->where('meta->provider', 'geniuspay')
            ->where('meta->reference', $reference)
            ->latest('id')
            ->first();

        if (!$transaction) {
            Log::warning('geniuspay:webhook reference not found', ['reference' => $reference, 'payload' => $payload]);
            return response()->json(['ok' => true]);
        }

        DB::transaction(function () use ($transaction, $payload, $isSuccess, $isFailed, $status) {
            $transaction->refresh();
            $meta = is_array($transaction->meta) ? $transaction->meta : [];

            if (($meta['status'] ?? null) === 'completed') {
                return;
            }

            $meta['webhook_last_payload'] = $payload;
            if ($status) {
                $meta['provider_status'] = $status;
            }

            if ($isSuccess) {
                $user = User::query()->lockForUpdate()->find($transaction->user_id);
                if ($user) {
                    $user->wallet_balance = max(0, (int) $user->wallet_balance + (int) $transaction->amount);
                    $user->save();
                }

                $transaction->type = 'credit';
                $meta['status'] = 'completed';
            } elseif ($isFailed) {
                $meta['status'] = 'failed';
            } else {
                $meta['status'] = $meta['status'] ?? 'pending';
            }

            $transaction->meta = $meta;
            $transaction->save();
        });

        return response()->json(['ok' => true]);
    }
}
