<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingCycle;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PlatformDashboardController — endpoint aggregated lintas-tenant untuk super-admin.
 *
 * Guard: role:super-admin. Tidak scoped ke tenant tertentu.
 */
class PlatformDashboardController extends Controller
{
    /**
     * GET /api/platform/overview — summary utama.
     * Resilient: setiap section di-try-catch sendiri agar tabel yang belum
     * di-migrate tidak crash seluruh endpoint.
     */
    public function overview(): JsonResponse
    {
        $today = now(config('app.timezone'))->toDateString();
        $monthStart = now(config('app.timezone'))->startOfMonth()->toDateTimeString();

        // --- Tenant stats ---
        $tenantStats = collect();
        $totalTenants = 0;
        try {
            $tenantStats = DB::connection('mysql_ops')->table('tenants')
                ->select('subscription_status', DB::raw('COUNT(*) as count'))
                ->groupBy('subscription_status')
                ->pluck('count', 'subscription_status');
            $totalTenants = $tenantStats->sum();
        } catch (\Throwable $e) {
        }

        // --- Billing stats (bulan ini) ---
        $totalBilled = 0;
        $totalPaid = 0;
        $totalOverdue = 0;
        $collectionRate = 0;
        try {
            $billingByStatus = DB::connection('mysql_ops')->table('billing_cycles')
                ->where('created_at', '>=', $monthStart)
                ->select('status', DB::raw('SUM(total_charge) as total'))
                ->groupBy('status')
                ->pluck('total', 'status');
            $totalBilled = $billingByStatus->sum();
            $totalPaid = (int) ($billingByStatus['paid'] ?? 0);
            $totalOverdue = (int) ($billingByStatus['overdue'] ?? 0) + (int) ($billingByStatus['issued'] ?? 0);
            $collectionRate = $totalBilled > 0 ? round($totalPaid / $totalBilled, 4) : 0;
        } catch (\Throwable $e) {
        }

        // --- Operations (today) ---
        $orderCount = 0;
        $revenueToday = 0;
        try {
            $orderStats = DB::connection('mysql_ops')->table('orders')
                ->where('status', 'completed')
                ->whereDate('created_at', $today)
                ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(grand_total), 0) as revenue')
                ->first();
            $orderCount = (int) ($orderStats->order_count ?? 0);
            $revenueToday = (int) round($orderStats->revenue ?? 0);
        } catch (\Throwable $e) {
        }

        // --- Stores ---
        $activeStores = 0;
        try {
            // Coba dengan deleted_at dulu (setelah migration), fallback tanpa filter
            $activeStores = (int) DB::connection('mysql_ops')->table('stores')
                ->whereNull('deleted_at')
                ->count();
        } catch (\Throwable $e) {
            try {
                $activeStores = (int) DB::connection('mysql_ops')->table('stores')->count();
            } catch (\Throwable $e2) {
            }
        }

        // --- Check-ins today ---
        $checkinsToday = 0;
        try {
            $checkinsToday = (int) DB::connection('mysql_ops')->table('attendances')
                ->whereDate('check_in', $today)
                ->distinct('created_by')
                ->count('created_by');
        } catch (\Throwable $e) {
        }

        return response()->json([
            'success' => true,
            'data' => [
                'tenants' => [
                    'total' => $totalTenants,
                    'active' => (int) ($tenantStats['active'] ?? 0),
                    'trialing' => (int) ($tenantStats['trialing'] ?? 0),
                    'suspended' => (int) ($tenantStats['suspended'] ?? 0),
                    'exempt' => (int) ($tenantStats['exempt'] ?? 0),
                ],
                'billing' => [
                    'total_charge' => (int) $totalBilled,
                    'total_paid' => $totalPaid,
                    'total_overdue' => $totalOverdue,
                    'collection_rate' => $collectionRate,
                ],
                'operations' => [
                    'total_orders_today' => $orderCount,
                    'total_revenue_today' => $revenueToday,
                    'active_stores' => $activeStores,
                    'checkins_today' => $checkinsToday,
                ],
            ],
        ]);
    }

    /**
     * GET /api/platform/tenants/recent?limit=5
     */
    public function recentTenants(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 5), 20);

        try {
            $tenants = Tenant::select('id', 'name', 'created_at', 'subscription_status', 'billing_exempt')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();
        } catch (\Throwable $e) {
            // Fallback: tanpa kolom billing yang mungkin belum di-migrate
            $tenants = Tenant::select('id', 'name', 'created_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();
        }

        return response()->json(['success' => true, 'data' => $tenants]);
    }

    /**
     * GET /api/platform/tenants/top-revenue?limit=5
     * Top tenant by omzet bulan ini.
     */
    public function topRevenue(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 5), 20);
        $monthStart = now(config('app.timezone'))->startOfMonth()->toDateTimeString();

        $rows = DB::connection('mysql_ops')->table('orders')
            ->join('tenants', 'orders.tenant_id', '=', 'tenants.id')
            ->where('orders.status', 'completed')
            ->where('orders.created_at', '>=', $monthStart)
            ->select(
                'tenants.id',
                'tenants.name',
                DB::raw('SUM(orders.grand_total) as revenue'),
                DB::raw('COUNT(orders.id) as order_count')
            )
            ->groupBy('tenants.id', 'tenants.name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'revenue' => (int) round($r->revenue),
                'order_count' => (int) $r->order_count,
            ]),
        ]);
    }

    /**
     * GET /api/platform/billing/overdue
     * Tenant dengan invoice overdue.
     */
    public function overdueBilling(): JsonResponse
    {
        $today = now(config('app.timezone'))->toDateString();
        $overdue = collect();

        try {
            $overdue = DB::connection('mysql_ops')->table('billing_cycles')
                ->join('tenants', 'billing_cycles.tenant_id', '=', 'tenants.id')
                ->whereIn('billing_cycles.status', ['issued', 'overdue'])
                ->where('billing_cycles.due_at', '<', $today)
                ->where('billing_cycles.total_charge', '>', 0)
                ->select(
                    'tenants.id as tenant_id',
                    'tenants.name as tenant_name',
                    DB::raw('SUM(billing_cycles.total_charge) as total_charge'),
                    DB::raw('MIN(billing_cycles.due_at) as earliest_due')
                )
                ->groupBy('tenants.id', 'tenants.name')
                ->orderByDesc('total_charge')
                ->limit(10)
                ->get();
        } catch (\Throwable $e) {
            // billing_cycles table mungkin belum ada
        }

        return response()->json([
            'success' => true,
            'data' => $overdue->map(fn($r) => [
                'tenant_id' => $r->tenant_id,
                'tenant_name' => $r->tenant_name,
                'total_charge' => (int) $r->total_charge,
                'due_at' => $r->earliest_due,
            ]),
        ]);
    }

    /**
     * GET /api/platform/growth?months=6
     * Pertumbuhan tenant per bulan.
     */
    public function growth(Request $request): JsonResponse
    {
        $months = min((int) $request->get('months', 6), 24);

        $data = [];
        $now = now(config('app.timezone'));

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = $now->copy()->subMonths($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $monthKey = $monthStart->format('Y-m');

            // Tenant baru di bulan itu
            $newTenants = (int) DB::connection('mysql_ops')->table('tenants')
                ->whereBetween('created_at', [$monthStart->toDateTimeString(), $monthEnd->toDateTimeString()])
                ->count();

            // Total tenant sampai akhir bulan itu
            $totalTenants = (int) DB::connection('mysql_ops')->table('tenants')
                ->where('created_at', '<=', $monthEnd->toDateTimeString())
                ->count();

            $data[] = [
                'month' => $monthKey,
                'label' => $monthStart->translatedFormat('M Y'),
                'new_tenants' => $newTenants,
                'total_tenants' => $totalTenants,
            ];
        }

        return response()->json(['success' => true, 'data' => $data]);
    }
}
