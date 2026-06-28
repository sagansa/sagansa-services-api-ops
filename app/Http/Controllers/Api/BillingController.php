<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingCycle;
use App\Models\BillingSettings;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use App\Services\Billing\PaymentProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BillingController — endpoint user-facing billing.
 *
 * Semua endpoint di-guard auth:sanctum, scope ke tenant aktif user.
 */
class BillingController extends Controller
{
    public function __construct(
        private BillingService $billingService,
    ) {}

    /**
     * Ambil tenant aktif user (dari header X-Active-Tenant atau default).
     */
    private function activeTenant(Request $request): ?Tenant
    {
        $user = $request->user();
        $tenantId = $request->header('X-Active-Tenant')
            ?? $user->tenant_id
            ?? null;

        if (! $tenantId) {
            return null;
        }

        return Tenant::find($tenantId);
    }

    /**
     * GET /billing/subscription — status subscription tenant aktif.
     */
    public function subscription(Request $request): JsonResponse
    {
        $tenant = $this->activeTenant($request);
        if (! $tenant) {
            return response()->json(['success' => false, 'message' => 'No active tenant'], 404);
        }

        $sub = $tenant->subscription;

        return response()->json([
            'success' => true,
            'data' => $sub,
        ]);
    }

    /**
     * GET /billing/dashboard — summary lengkap.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $tenant = $this->activeTenant($request);
        if (! $tenant) {
            return response()->json(['success' => false, 'message' => 'No active tenant'], 404);
        }

        $sub = $tenant->subscription;

        // Estimasi real-time
        $preview = null;
        if ($sub && $sub->plan) {
            $preview = $this->billingService->preview($tenant, $sub->plan);
        }

        // Current cycle (issued/overdue)
        $currentCycle = BillingCycle::where('tenant_id', $tenant->id)
            ->whereIn('status', ['issued', 'overdue'])
            ->latest('issued_at')
            ->first();

        // Overdue cycles
        $overdue = BillingCycle::where('tenant_id', $tenant->id)
            ->overdue()
            ->get();

        $isSuspended = $this->billingService->shouldSuspend($tenant);

        return response()->json([
            'success' => true,
            'data' => [
                'subscription' => $sub,
                'current_cycle' => $currentCycle,
                'preview' => $preview,
                'overdue_cycles' => $overdue,
                'is_suspended' => $isSuspended,
                'trial_days_remaining' => $sub?->trialDaysRemaining() ?? null,
            ],
        ]);
    }

    /**
     * GET /billing/cycles — list invoice (paginated).
     */
    public function cycles(Request $request): JsonResponse
    {
        $tenant = $this->activeTenant($request);
        if (! $tenant) {
            return response()->json(['success' => false, 'message' => 'No active tenant'], 404);
        }

        $perPage = (int) $request->get('per_page', 15);
        $query = BillingCycle::where('tenant_id', $tenant->id)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
        ]);
    }

    /**
     * GET /billing/cycles/current — invoice bulan berjalan.
     */
    public function currentCycle(Request $request): JsonResponse
    {
        $tenant = $this->activeTenant($request);
        if (! $tenant) {
            return response()->json(['success' => false, 'message' => 'No active tenant'], 404);
        }

        $now = now(config('app.timezone'));
        $cycle = BillingCycle::where('tenant_id', $tenant->id)
            ->where('period_year', $now->year)
            ->where('period_month', $now->month)
            ->first();

        return response()->json([
            'success' => true,
            'data' => $cycle,
        ]);
    }

    /**
     * GET /billing/cycles/{id} — detail invoice.
     */
    public function showCycle(Request $request, string $id): JsonResponse
    {
        $tenant = $this->activeTenant($request);
        if (! $tenant) {
            return response()->json(['success' => false, 'message' => 'No active tenant'], 404);
        }

        $cycle = BillingCycle::where('tenant_id', $tenant->id)->find($id);

        if (! $cycle) {
            return response()->json(['success' => false, 'message' => 'Invoice not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $cycle->load('payments'),
        ]);
    }

    /**
     * GET /billing/preview — estimasi real-time bulan berjalan.
     */
    public function preview(Request $request): JsonResponse
    {
        $tenant = $this->activeTenant($request);
        if (! $tenant) {
            return response()->json(['success' => false, 'message' => 'No active tenant'], 404);
        }

        $sub = $tenant->subscription;
        if (! $sub || ! $sub->plan) {
            return response()->json(['success' => false, 'message' => 'No active subscription'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->billingService->preview($tenant, $sub->plan),
        ]);
    }

    /**
     * POST /billing/cycles/{id}/pay — generate provider invoice.
     */
    public function pay(Request $request, string $id): JsonResponse
    {
        $tenant = $this->activeTenant($request);
        if (! $tenant) {
            return response()->json(['success' => false, 'message' => 'No active tenant'], 404);
        }

        $cycle = BillingCycle::where('tenant_id', $tenant->id)->find($id);
        if (! $cycle) {
            return response()->json(['success' => false, 'message' => 'Invoice not found'], 404);
        }

        if ($cycle->status === 'paid') {
            return response()->json(['success' => false, 'message' => 'Invoice already paid'], 422);
        }

        // Jika sudah ada provider_invoice_url, return yang lama (jangan buat baru)
        if ($cycle->provider_invoice_url) {
            return response()->json([
                'success' => true,
                'data' => ['provider_invoice_url' => $cycle->provider_invoice_url],
            ]);
        }

        try {
            $provider = app(PaymentProviderManager::class)->resolve();
            $result = $provider->createInvoice($cycle);

            $cycle->update([
                'payment_provider' => BillingSettings::singleton()->active_provider,
                'provider_invoice_id' => $result['provider_invoice_id'],
                'provider_invoice_url' => $result['provider_invoice_url'],
            ]);

            return response()->json([
                'success' => true,
                'data' => ['provider_invoice_url' => $result['provider_invoice_url']],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
