<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class GeniusPayService
{
    private function baseUrl(): string
    {
        $base = trim((string) env('GENIUSPAY_MERCHANT_BASE_URL', 'https://pay.genius.ci/api/v1/merchant'));
        return rtrim($base, '/');
    }

    private function apiKey(): string
    {
        return trim((string) env('GENIUSPAY_API_KEY', ''));
    }

    /**
     * @param array{
     *   amount:int,
     *   currency?:string,
     *   customer:array{phone:string,name?:string,email?:string},
     *   success_url?:string|null,
     *   error_url?:string|null,
     *   metadata?:array<string,mixed>
     * } $input
     * @return array<string,mixed>
     */
    public function createWavePayment(array $input): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return [
                'error' => 'GENIUSPAY_API_KEY manquante',
                'hint' => 'Renseignez GENIUSPAY_API_KEY dans votre .env',
            ];
        }

        $payload = [
            'amount' => (int) $input['amount'],
            'currency' => $input['currency'] ?? 'XOF',
            'gateway' => 'wave',
            'customer' => [
                'phone' => (string) ($input['customer']['phone'] ?? ''),
                'name' => (string) ($input['customer']['name'] ?? ''),
                'email' => (string) ($input['customer']['email'] ?? ''),
            ],
            'success_url' => $input['success_url'] ?? null,
            'error_url' => $input['error_url'] ?? null,
            'metadata' => $input['metadata'] ?? null,
        ];

        $payload = array_filter($payload, fn ($value) => $value !== null && $value !== '');

        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->withToken($apiKey)
                ->withHeaders([
                    'X-API-Key' => $apiKey,
                ])
                ->post($this->baseUrl() . '/payments', $payload)
                ->throw();
        } catch (RequestException $exception) {
            $res = $exception->response;
            return [
                'error' => 'GENIUSPAY_REQUEST_FAILED',
                'status' => $res?->status(),
                'body' => $res?->json() ?? $res?->body(),
            ];
        }

        return $response->json() ?? [];
    }

    /**
     * @param array{
     *   wallet_id:int|string,
     *   amount:int,
     *   recipient_name:string,
     *   recipient_phone:string,
     *   account_number:string,
     *   provider?:string|null,
     *   description?:string|null,
     *   metadata?:array<string,mixed>
     * } $input
     * @return array<string,mixed>
     */
    public function createMobileMoneyPayout(array $input): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return [
                'error' => 'GENIUSPAY_API_KEY manquante',
                'hint' => 'Renseignez GENIUSPAY_API_KEY dans votre .env',
            ];
        }

        $payload = [
            'wallet_id' => $input['wallet_id'],
            'amount' => (int) $input['amount'],
            'currency' => 'XOF',
            'recipient_name' => (string) $input['recipient_name'],
            'recipient_phone' => (string) $input['recipient_phone'],
            'provider' => $input['provider'] ?? null,
            'account_number' => (string) $input['account_number'],
            'description' => $input['description'] ?? null,
            'metadata' => $input['metadata'] ?? null,
        ];

        $payload = array_filter($payload, fn ($value) => $value !== null && $value !== '');

        try {
            $response = Http::acceptJson()
                ->timeout(25)
                ->withToken($apiKey)
                ->withHeaders([
                    'X-API-Key' => $apiKey,
                ])
                ->post($this->baseUrl() . '/payouts', $payload)
                ->throw();
        } catch (RequestException $exception) {
            $res = $exception->response;
            return [
                'error' => 'GENIUSPAY_REQUEST_FAILED',
                'status' => $res?->status(),
                'body' => $res?->json() ?? $res?->body(),
            ];
        }

        return $response->json() ?? [];
    }
}
