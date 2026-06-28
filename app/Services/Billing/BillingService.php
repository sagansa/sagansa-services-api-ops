<?php

namespace App\Services\Billing;

use App\Models\BillingCycle;
use App\Models\Plan;
use App\Models\PlanDiscount;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BillingService — inti perhitungan charge SAGANSA.
 *
 * POS     = min(omzet_store × rate%, base_charge) per store, dikurangi diskon.
 * Attendance = gratis bila pakai POS; else (karyawan_aktif − free) × rate.
 *
 * Omzet dihitung dari tabel `orders` WHERE status='completed'.
 */
class BillingService
{
    /**
     * Hitung omzet per-store untuk tenant pada bulan tertentu.
     *
     * @return array<int, array{store_id: string, store_name: string, revenue: int, charge: int}>
     */
    public function calculateStoreRevenue(Tenant $tenant, int $year, int $month): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = sprintf('%04d-%02d-%02d', $year, $month, (int) date('t', strtotime($start)));

        $rows = DB::connection('mysql_ops')->table('orders')
            ->join('stores', 'orders.store_id', '=', 'stores.id')
            ->where('orders.tenant_id', $tenant->id)
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->whereNull('stores.deleted_at')
            ->select(
                'stores.id as store_id',
                'stores.name as store_name',
                DB::raw('SUM(orders.grand_total) as revenue'),
                DB::raw('COUNT(orders.id) as order_count')
            )
            ->groupBy('stores.id', 'stores.name')
            ->get();

        return $rows->map(fn($r) => [
            'store_id' => $r->store_id,
            'store_name' => $r->store_name,
            'revenue' => (int) round($r->revenue),
            'order_count' => (int) $r->order_count,
        ])->all();
    }

    /**
     * Hitung charge POS per-store dengan cap.
     *
     * @return array{breakdown: array, total: int}
     */
    public function calculatePosCharge(array $storeRevenues, Plan $plan): array
    {
        $breakdown = [];
        $total = 0;

        foreach ($storeRevenues as $item) {
            $raw = (int) round($item['revenue'] * (float) $plan->pos_rate_percent);
            $capped = min($raw, $plan->pos_base_charge);
            $breakdown[] = [
                'store_id' => $item['store_id'],
                'store_name' => $item['store_name'],
                'revenue' => $item['revenue'],
                'charge' => $capped,
            ];
            $total += $capped;
        }

        return ['breakdown' => $breakdown, 'total' => $total];
    }

    /**
     * Hitung karyawan aktif (team member dengan ≥1 check-in di bulan itu).
     */
    public function countActiveEmployees(Tenant $tenant, int $year, int $month): int
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = sprintf('%04d-%02d-%02d', $year, $month, (int) date('t', strtotime($start)));

        return (int) DB::connection('mysql_ops')->table('attendances')
            ->join('tenant_user', 'attendances.created_by', '=', 'tenant_user.user_id')
            ->where('attendances.tenant_id', $tenant->id)
            ->where('tenant_user.tenant_id', $tenant->id)
            ->whereBetween('attendances.check_in', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->distinct('attendances.created_by')
            ->count('attendances.created_by');
    }

    /**
     * Hitung charge attendance.
     * Gratis bila tenant pakai POS (ada omzet > 0).
     */
    public function calculateAttendanceCharge(int $activeEmployees, bool $hasPosUsage, Plan $plan): int
    {
        if ($hasPosUsage) {
            return 0;
        }
        if ($activeEmployees <= $plan->attendance_free_count) {
            return 0;
        }
        return ($activeEmployees - $plan->attendance_free_count) * $plan->attendance_rate;
    }

    /**
     * Hitung diskon untuk nominal charge tertentu.
     */
    public function calculateDiscount(int $amount, Collection $discounts): array
    {
        if ($discounts->isEmpty() || $amount <= 0) {
            return ['discount' => 0, 'discount_id' => null];
        }

        // Ambil diskon dengan nilai tertinggi (single)
        $best = $discounts->reduce(function ($carry, PlanDiscount $d) use ($amount) {
            $val = $d->type === 'percentage'
                ? (int) round($amount * ((float) $d->value / 100))
                : (int) round($d->value);
            if ($carry === null || $val > $carry['discount']) {
                return ['discount' => min($val, $amount), 'discount_id' => $d->id];
            }
            return $carry;
        });

        return $best ?? ['discount' => 0, 'discount_id' => null];
    }

    /**
     * Snapshot pricing untuk disimpan immutable di billing_cycle.
     */
    public function snapshotPlan(Plan $plan): array
    {
        return [
            'code' => $plan->code,
            'pos_rate_percent' => (float) $plan->pos_rate_percent,
            'pos_base_charge' => $plan->pos_base_charge,
            'attendance_rate' => $plan->attendance_rate,
            'attendance_free_count' => $plan->attendance_free_count,
            'discounts' => $plan->activeDiscounts()->map(fn($d) => [
                'id' => $d->id,
                'code' => $d->code,
                'type' => $d->type,
                'value' => (float) $d->value,
                'applies_to' => $d->applies_to,
            ])->values()->all(),
        ];
    }

    /**
     * Generate billing cycle lengkap untuk tenant pada periode tertentu.
     *
     * @return BillingCycle
     */
    public function generateCycle(Tenant $tenant, Subscription $subscription, int $year, int $month): BillingCycle
    {
        $plan = $subscription->plan;

        // 1. Omzet per-store
        $revenues = $this->calculateStoreRevenue($tenant, $year, $month);
        $hasPosUsage = count($revenues) > 0;

        // 2. POS charge
        $pos = $this->calculatePosCharge($revenues, $plan);

        // 3. Attendance charge
        $activeEmployees = $this->countActiveEmployees($tenant, $year, $month);
        $attendanceCharge = $this->calculateAttendanceCharge($activeEmployees, $hasPosUsage, $plan);

        // 4. Diskon (applies_to 'total' atau 'pos')
        $posDiscounts = $plan->activeDiscounts('pos');
        $posDiscountResult = $this->calculateDiscount($pos['total'], $posDiscounts);
        $posDiscount = $posDiscountResult['discount'];

        $totalBeforeDiscount = $pos['total'] + $attendanceCharge;
        $total = $totalBeforeDiscount - $posDiscount;

        // 5. Simpan (idempoten via updateOrCreate)
        return BillingCycle::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'period_year' => $year,
                'period_month' => $month,
            ],
            [
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'pos_charge' => $pos['total'],
                'attendance_charge' => $attendanceCharge,
                'discount_amount' => $posDiscount,
                'total_charge' => max(0, $total),
                'pos_breakdown' => $pos['breakdown'],
                'attendance_employees_count' => $activeEmployees,
                'snapshot_plan' => $this->snapshotPlan($plan),
                'status' => 'issued',
                'issued_at' => now(config('app.timezone'))->toDateString(),
                'due_at' => now(config('app.timezone'))->addDays(5)->toDateString(),
            ]
        );
    }

    /**
     * Estimasi real-time untuk bulan berjalan.
     */
    public function preview(Tenant $tenant, Plan $plan): array
    {
        $now = now(config('app.timezone'));
        $year = $now->year;
        $month = $now->month;

        $revenues = $this->calculateStoreRevenue($tenant, $year, $month);
        $hasPosUsage = count($revenues) > 0;

        $pos = $this->calculatePosCharge($revenues, $plan);
        $activeEmployees = $this->countActiveEmployees($tenant, $year, $month);
        $attendanceCharge = $this->calculateAttendanceCharge($activeEmployees, $hasPosUsage, $plan);

        $posDiscounts = $plan->activeDiscounts('pos');
        $posDiscountResult = $this->calculateDiscount($pos['total'], $posDiscounts);
        $posDiscount = $posDiscountResult['discount'];

        $total = max(0, ($pos['total'] + $attendanceCharge) - $posDiscount);

        return [
            'period_year' => $year,
            'period_month' => $month,
            'pos_charge' => $pos['total'],
            'attendance_charge' => $attendanceCharge,
            'discount_amount' => $posDiscount,
            'total_charge' => $total,
            'pos_breakdown' => $pos['breakdown'],
            'attendance_employees_count' => $activeEmployees,
            'has_pos_usage' => $hasPosUsage,
        ];
    }

    /**
     * Tentukan apakah tenant seharusnya di-suspend.
     */
    public function shouldSuspend(Tenant $tenant): bool
    {
        if ($tenant->billing_exempt) {
            return false;
        }

        $sub = $tenant->subscription;
        if (! $sub || $sub->isOnTrial()) {
            return false;
        }

        // Ada invoice overdue belum dibayar
        return BillingCycle::where('tenant_id', $tenant->id)
            ->whereIn('status', ['issued', 'overdue'])
            ->where('due_at', '<', now(config('app.timezone'))->toDateString())
            ->exists();
    }
}
