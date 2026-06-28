<?php

namespace App\Services\Billing;

use App\Models\BillingCycle;
use App\Models\BillingSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MidtransProvider — integrasi Midtrans Payment API (Snap).
 *
 * Menggunakan HTTP facade Laravel. Server key untuk Basic Auth (base64).
 * Mode production/sandbox ditentukan dari billing_settings.midtrans_is_production.
 *
 * Ref: https://docs.midtrans.com/
 */
class MidtransProvider implements PaymentProviderInterface
{
    private function baseUrl(): string
    {
        $settings = BillingSettings::singleton();
        return $settings->midtrans_is_production
            ? 'https://app.midtrans.com/snap/v1'
            : 'https://app.sandbox.midtrans.com/snap/v1';
    }

    private function authHeader(): string
    {
        $settings = BillingSettings::singleton();
        $serverKey = $settings->midtrans_server_key ?? '';
        return 'Basic ' . base64_encode($serverKey . ':');
    }

    public function createInvoice(BillingCycle $cycle): array
    {
        $tenant = $cycle->tenant;
        $orderId = 'SAGANSA-' . $cycle->tenant_id . '-' . $cycle->period_year . str_pad((string) $cycle->period_month, 2, '0', STR_PAD_LEFT);

        $response = Http::withHeaders([
            'Authorization' => $this->authHeader(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($this->baseUrl() . '/transactions', [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $cycle->total_charge,
            ],
            'item_details' => [[
                'id' => 'billing-' . $cycle->id,
                'price' => $cycle->total_charge,
                'quantity' => 1,
                'name' => "SAGANSA Billing {$cycle->period_year}-" . str_pad((string) $cycle->period_month, 2, '0', STR_PAD_LEFT),
            ]],
            'customer_details' => [
                'first_name' => $tenant?->name ?? 'Tenant',
            ],
            'callbacks' => [
                'finish' => config('app.url') . '/billing',
            ],
        ]);

        if (! $response->successful()) {
            Log::error('Midtrans createTransaction failed', [
                'cycle_id' => $cycle->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to create Midtrans transaction: ' . $response->body());
        }

        $data = $response->json();

        return [
            'provider_invoice_id' => $data['token'] ?? $orderId,
            'provider_invoice_url' => $data['redirect_url'] ?? '',
        ];
    }

    public function verifyWebhook(Request $request): bool
    {
        // Midtrans pakai SignatureKey = SHA512(order_id+status_code+gross_amount+server_key)
        $settings = BillingSettings::singleton();
        $serverKey = $settings->midtrans_server_key ?? '';

        $orderId = $request->input('order_id', '');
        $statusCode = $request->input('status_code', '');
        $grossAmount = $request->input('gross_amount', '');
        $signatureKey = $request->input('signature_key', '');

        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        return hash_equals($expected, $signatureKey);
    }

    public function parseWebhook(Request $request): array
    {
        $transactionStatus = strtolower($request->input('transaction_status', 'unknown'));
        $fraudStatus = strtolower($request->input('fraud_status', 'accept'));

        $paid = in_array($transactionStatus, ['capture', 'settlement'])
            || ($transactionStatus === 'capture' && $fraudStatus === 'accept');

        return [
            'provider_payment_id' => $request->input('transaction_id'),
            'amount' => (int) $request->input('gross_amount', 0),
            'method' => $request->input('payment_type', 'UNKNOWN'),
            'status' => $transactionStatus,
            'paid_at' => $paid ? $request->input('transaction_time', now()->toIso8601String()) : null,
        ];
    }

    public function getInvoiceStatus(string $providerInvoiceId): array
    {
        // Midtrans: cek status via Core API /v2/{order_id}/status
        $settings = BillingSettings::singleton();
        $baseUrl = $settings->midtrans_is_production
            ? 'https://api.midtrans.com/v2'
            : 'https://api.sandbox.midtrans.com/v2';

        $response = Http::withHeaders([
            'Authorization' => $this->authHeader(),
            'Accept' => 'application/json',
        ])->get($baseUrl . '/' . $providerInvoiceId . '/status');

        if (! $response->successful()) {
            return ['status' => 'UNKNOWN', 'paid' => false];
        }

        $data = $response->json();
        $status = strtolower($data['transaction_status'] ?? 'unknown');

        return [
            'status' => $status,
            'paid' => in_array($status, ['capture', 'settlement']),
        ];
    }
}
