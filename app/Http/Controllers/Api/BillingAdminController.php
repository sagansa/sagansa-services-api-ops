<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingSettings;
use App\Models\Plan;
use App\Models\PlanDiscount;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BillingAdminController — endpoint konfigurasi billing (super-admin only).
 *
 * Di-guard middleware role:super-admin di routes.
 */
class BillingAdminController extends Controller
{
    // ---- Settings (provider config) ----

    public function getSettings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => BillingSettings::singleton(),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'active_provider' => ['sometimes', 'in:xendit,midtrans'],
            'xendit_secret_key' => ['sometimes', 'nullable', 'string'],
            'xendit_verify_key' => ['sometimes', 'nullable', 'string'],
            'midtrans_server_key' => ['sometimes', 'nullable', 'string'],
            'midtrans_client_key' => ['sometimes', 'nullable', 'string'],
            'midtrans_is_production' => ['sometimes', 'boolean'],
            'webhook_secret' => ['sometimes', 'nullable', 'string'],
        ]);

        $settings = BillingSettings::singleton();
        // Hanya update field yang dikirim (dan bukan empty untuk keys — izinkan kosong utk clear)
        $settings->fill($data);
        $settings->updated_by = $request->user()?->uuid;
        $settings->save();

        return response()->json([
            'success' => true,
            'data' => $settings->fresh(),
        ]);
    }

    // ---- Plans ----

    public function getPlans(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Plan::with('discounts')->get(),
        ]);
    }

    public function updatePlan(Request $request, string $id): JsonResponse
    {
        $plan = Plan::find($id);
        if (! $plan) {
            return response()->json(['success' => false, 'message' => 'Plan not found'], 404);
        }

        $data = $request->validate([
            'code' => ['sometimes', 'string'],
            'name' => ['sometimes', 'string'],
            'pos_rate_percent' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'pos_base_charge' => ['sometimes', 'integer', 'min:0'],
            'attendance_rate' => ['sometimes', 'integer', 'min:0'],
            'attendance_free_count' => ['sometimes', 'integer', 'min:0'],
            'trial_months' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $plan->update($data);

        return response()->json([
            'success' => true,
            'data' => $plan->fresh(),
        ]);
    }

    // ---- Discounts ----

    public function getDiscounts(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => PlanDiscount::all(),
        ]);
    }

    public function createDiscount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string'],
            'type' => ['required', 'in:percentage,fixed'],
            'value' => ['required', 'numeric', 'min:0'],
            'applies_to' => ['required', 'in:pos,attendance,total'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $discount = PlanDiscount::create($data);

        return response()->json([
            'success' => true,
            'data' => $discount,
        ], 201);
    }

    public function updateDiscount(Request $request, string $id): JsonResponse
    {
        $discount = PlanDiscount::find($id);
        if (! $discount) {
            return response()->json(['success' => false, 'message' => 'Discount not found'], 404);
        }

        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:50'],
            'name' => ['sometimes', 'string'],
            'type' => ['sometimes', 'in:percentage,fixed'],
            'value' => ['sometimes', 'numeric', 'min:0'],
            'applies_to' => ['sometimes', 'in:pos,attendance,total'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $discount->update($data);

        return response()->json([
            'success' => true,
            'data' => $discount->fresh(),
        ]);
    }

    public function deleteDiscount(string $id): JsonResponse
    {
        $discount = PlanDiscount::find($id);
        if (! $discount) {
            return response()->json(['success' => false, 'message' => 'Discount not found'], 404);
        }

        $discount->delete();

        return response()->json(['success' => true]);
    }

    // ---- Tenant exemption & overview ----

    /**
     * GET /billing/admin/tenants — list all tenants with billing status (super-admin).
     */
    public function getTenants(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 25);
        $query = Tenant::with(['subscription.plan'])
            ->select('id', 'name', 'owner_id', 'billing_exempt', 'subscription_status', 'created_at')
            ->orderByDesc('created_at');

        $search = $request->get('search');
        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        $tenants = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $tenants,
        ]);
    }

    /**
     * GET /billing/admin/billing-overview — list billing cycles across all tenants.
     */
    public function billingOverview(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 25);
        $query = \App\Models\BillingCycle::with(['tenant:id,name', 'payments'])
            ->orderByDesc('period_year')
            ->orderByDesc('period_month');

        $status = $request->get('status');
        if ($status) {
            $query->where('status', $status);
        }

        $search = $request->get('search');
        if ($search) {
            $query->whereHas('tenant', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $cycles = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $cycles,
        ]);
    }

    public function setExemption(Request $request, string $tenantId): JsonResponse
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return response()->json(['success' => false, 'message' => 'Tenant not found'], 404);
        }

        $data = $request->validate([
            'billing_exempt' => ['required', 'boolean'],
        ]);

        $tenant->update([
            'billing_exempt' => $data['billing_exempt'],
            'subscription_status' => $data['billing_exempt'] ? 'exempt' : $tenant->subscription_status,
        ]);

        return response()->json([
            'success' => true,
            'data' => $tenant->fresh(),
        ]);
    }
}
