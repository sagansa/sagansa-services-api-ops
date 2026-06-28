<?php

namespace App\Services\Billing;

use App\Models\BillingCycle;
use App\Models\BillingSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * XenditProvider — integrasi Xendit Invoice API.
 *
 * Menggunakan HTTP facade Laravel (built-in Guzzle). Tidak butuh SDK tambahan.
 * API key diambil dari billing_settings (disimpan terenkripsi).
 *
 * Ref: https://developers.xendit.co/api-reference/#invoices
 */
class XenditProvider implements PaymentProviderInterface
{
    private function baseUrl(): string
    {
        // Xendit punya satu base URL untuk semua environment (key menentukan mode)
        return 'https://api.xendit.co';
    }

    private function auth(): array
    {
        $settings = BillingSettings::singleton();
        return [
            $settings->xendit_secret_key ?? '',
            '',
        ];
    }

    public function createInvoice(BillingCycle $cycle): array
    {
        [$user, $pass] = $this->auth();

        $tenant = $cycle->tenant;
        $externalId = 'SAGANSA-' . $cycle->tenant_id . '-' . $cycle->period_year . str_pad((string) $cycle->period_month, 2, '0', STR_PAD_LEFT);

        $response = Http::withBasicAuth($user, $pass)
            ->post($this->baseUrl() . '/payment_requests', [
                'amount' => $cycle->total_charge,
                'currency' => 'IDR',
                'reference_id' => $externalId,
                'description' => "SAGANSA Billing - {$cycle->period_year}-" . str_pad((string) $cycle->period_month, 2, '0', STR_PAD_LEFT),
                'payment_method' => [
                    'type' => 'EWALLET',
                    // Xendit payment request mendukung multi-method via invoice; fallback ke invoice classic
                ],
                'customer' => [
                    'reference_id' => $tenant?->id,
                ],
                'success_return_url' => config('app.url') . '/billing',
                'failure_return_url' => config('app.url') . '/billing',
            ]);

        if (! $response->successful()) {
            Log::error('Xendit createInvoice failed', [
                'cycle_id' => $cycle->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to create Xendit invoice: ' . $response->body());
        }

        $data = $response->json();

        // Fallback: pakai Invoice API classic jika payment_request tidak return invoice URL
        $invoiceUrl = $data['actions']['desktop_web_checkout_url']
            ?? $data['invoice_url']
            ?? null;

        if (! $invoiceUrl) {
            // Buat via Invoice endpoint (legacy, lebih kompatibel)
            $invResponse = Http::withBasicAuth($user, $pass)
                ->post($this->baseUrl() . '/v2/invoices', [
                    'external_id' => $externalId,
                    'amount' => $cycle->total_charge,
                    'description' => "SAGANSA Billing - {$cycle->period_year}-" . str_pad((string) $cycle->period_month, 2, '0', STR_PAD_LEFT),
                    'currency' => 'IDR',
                    'success_redirect_url' => config('app.url') . '/billing',
                    'failure_redirect_url' => config('app.url') . '/billing',
                ]);

            if (! $invResponse->successful()) {
                throw new \RuntimeException('Failed to create Xendit invoice: ' . $invResponse->body());
            }

            $invData = $invResponse->json();
            return [
                'provider_invoice_id' => $invData['id'],
                'provider_invoice_url' => $invData['invoice_url'],
            ];
        }

        return [
            'provider_invoice_id' => $data['id'] ?? $externalId,
            'provider_invoice_url' => $invoiceUrl,
        ];
    }

    public function verifyWebhook(Request $request): bool
    {
        // Xendit webhook pakai header X-CALLBACK-TOKEN yang harus cocok verify_key
        $token = $request->header('X-CALLBACK-TOKEN');
        $settings = BillingSettings::singleton();
        return $token !== null && $token === $settings->xendit_verify_key;
    }

    public function parseWebhook(Request $request): array
    {
        $payload = $request->all();

        $status = $payload['status'] ?? 'UNKNOWN';
        $paid = in_array(strtoupper($status), ['PAID', 'SETTLED', 'COMPLETED']);

        return [
            'provider_payment_id' => $payload['payment_id'] ?? $payload['id'] ?? null,
            'amount' => (int) ($payload['paid_amount'] ?? $payload['amount'] ?? 0),
            'method' => $payload['payment_method'] ?? $payload['payment_channel'] ?? 'UNKNOWN',
            'status' => $status,
            'paid_at' => $paid ? ($payload['paid_at'] ?? $payload['updated'] ?? now()->toIso8601String()) : null,
        ];
    }

    public function getInvoiceStatus(string $providerInvoiceId): array
    {
        [$user, $pass] = $this->auth();

        $response = Http::withBasicAuth($user, $pass)
            ->get($this->baseUrl() . '/v2/invoices/' . $providerInvoiceId);

        if (! $response->successful()) {
            return ['status' => 'UNKNOWN', 'paid' => false];
        }

        $data = $response->json();
        $status = strtoupper($data['status'] ?? 'UNKNOWN');

        return [
            'status' => $status,
            'paid' => in_array($status, ['PAID', 'SETTLED']),
        ];
    }
}
