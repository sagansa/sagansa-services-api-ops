<?php

namespace App\Services\Billing;

use App\Models\BillingCycle;
use Illuminate\Http\Request;

/**
 * Abstraction untuk payment provider (Xendit / Midtrans).
 *
 * Implementasi konkret membungkus HTTP API masing-masing provider.
 * Provider aktif di-resolve oleh PaymentProviderManager dari billing_settings.
 */
interface PaymentProviderInterface
{
    /**
     * Buat/generate invoice di sisi provider untuk billing cycle.
     *
     * @return array{provider_invoice_id: string, provider_invoice_url: string}
     */
    public function createInvoice(BillingCycle $cycle): array;

    /**
     * Validasi webhook callback (cek signature/token).
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * Parse payload webhook menjadi data pembayaran yang seragam.
     *
     * @return array{provider_payment_id: ?string, amount: int, method: string, status: string, paid_at: ?string}
     */
    public function parseWebhook(Request $request): array;

    /**
     * Cek status invoice di provider (untuk reconciling).
     *
     * @return array{status: string, paid: bool}
     */
    public function getInvoiceStatus(string $providerInvoiceId): array;
}
