<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentReturnController extends Controller
{
    public function handle(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:160'],
            'target' => ['nullable', 'string', 'max:2048'],
        ]);

        $status = strtolower(trim((string) ($validated['status'] ?? '')));
        $reference = trim((string) ($validated['reference'] ?? ''));
        $target = trim((string) ($validated['target'] ?? ''));

        if ($status === 'success' && $reference !== '') {
            $this->confirmSandboxTopup($reference);
        }

        if ($target === '') {
            $scheme = trim((string) env('ZIWAGO_APP_SCHEME', 'ziwago'));
            $target = "{$scheme}://wallet/topup-callback";
        }

        $separator = str_contains($target, '?') ? '&' : '?';
        $targetUrl = $target . $separator . http_build_query([
            'status' => $status !== '' ? $status : null,
            'reference' => $reference !== '' ? $reference : null,
        ]);

        return response()->view('payment-return', [
            'targetUrl' => $targetUrl,
        ]);
    }

    private function confirmSandboxTopup(string $reference): void
    {
        $env = strtolower(trim((string) env('APP_ENV', 'production')));
        $allow = $env !== 'production' || (bool) env('GENIUSPAY_CONFIRM_ON_RETURN', false);
        if (!$allow) {
            return;
        }

        try {
            DB::transaction(function () use ($reference) {
                /** @var WalletTransaction|null $tx */
                $tx = WalletTransaction::query()
                    ->lockForUpdate()
                    ->where('type', 'topup_pending')
                    ->where('method', 'wave')
                    ->where('meta->provider', 'geniuspay')
                    ->where('meta->reference', $reference)
                    ->latest('id')
                    ->first();

                if (!$tx) return;

                $meta = is_array($tx->meta) ? $tx->meta : [];
                if (($meta['status'] ?? null) === 'completed') return;

                /** @var User|null $user */
                $user = User::query()->lockForUpdate()->find($tx->user_id);
                if (!$user) return;

                $user->wallet_balance = max(0, (int) $user->wallet_balance + (int) $tx->amount);
                $user->save();

                $tx->type = 'credit';
                $meta['status'] = 'completed';
                $meta['confirmed_via'] = 'return_url';
                $tx->meta = $meta;
                $tx->save();
            });
        } catch (\Throwable $exception) {
            Log::warning('geniuspay:return confirm failed', [
                'reference' => $reference,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
