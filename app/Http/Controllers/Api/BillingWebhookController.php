<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingCycle;
use App\Models\BillingPayment;
use App\Services\Billing\PaymentProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * BillingWebhookController — handle callback dari Xendit/Midtrans.
 *
 * Webhook di-guard via verifyWebhook() masing-masing provider (signature/token).
 * Tidak butuh auth:sanctum (publik tapi terverifikasi).
 */
class BillingWebhookController extends Controller
{
    public function handleXendit(Request $request): JsonResponse
    {
        return $this->handle($request, 'xendit');
    }

    public function handleMidtrans(Request $request): JsonResponse
    {
        return $this->handle($request, 'midtrans');
    }

    private function handle(Request $request, string $providerName): JsonResponse
    {
        try {
            $provider = app(PaymentProviderManager::class)->resolveByName($providerName);

            if (! $provider->verifyWebhook($request)) {
                Log::warning("Billing webhook {$providerName} verification failed", [
                    'ip' => $request->ip(),
                ]);
                return response()->json(['success' => false, 'message' => 'Invalid webhook signature'], 403);
            }

            $data = $provider->parseWebhook($request);

            // Cari billing cycle by provider invoice id atau external id
            $externalId = $request->input('external_id') ?? $request->input('order_id');
            $cycle = null;

            if ($externalId) {
                // external id format: SAGANSA-{tenant_id}-{period}
                $cycle = BillingCycle::where('provider_invoice_id', $data['provider_payment_id'])
                    ->orWhere('provider_invoice_id', $externalId)
                    ->first();
            }
            if (! $cycle && $data['provider_payment_id']) {
                $cycle = BillingCycle::where('provider_invoice_id', $data['provider_payment_id'])->first();
            }

            if (! $cycle) {
                Log::warning("Billing webhook {$providerName}: billing cycle not found", [
                    'external_id' => $externalId,
                    'provider_payment_id' => $data['provider_payment_id'],
                ]);
                return response()->json(['success' => false, 'message' => 'Billing cycle not found'], 404);
            }

            // Record payment
            BillingPayment::create([
                'billing_cycle_id' => $cycle->id,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'provider' => $providerName,
                'provider_payment_id' => $data['provider_payment_id'],
                'paid_at' => $data['paid_at'] ? now() : null,
                'metadata' => $request->all(),
            ]);

            // Jika paid, mark cycle paid & unsuspend
            if ($data['paid_at'] !== null) {
                $cycle->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);

                // Un-suspend subscription jika ada
                if ($cycle->subscription && $cycle->subscription->status === 'suspended') {
                    $cycle->subscription->update(['status' => 'active']);
                    $cycle->tenant->update(['subscription_status' => 'active']);
                }

                Log::info("Billing cycle {$cycle->id} marked paid via {$providerName} webhook");
            }

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error("Billing webhook {$providerName} error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
