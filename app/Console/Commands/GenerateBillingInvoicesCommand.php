<?php

namespace App\Console\Commands;

use App\Models\BillingCycle;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Generate billing invoices untuk semua tenant aktif.
 *
 * Dijalankan setiap tanggal 5 oleh scheduler. Menghitung omzet bulan SEBELUMnya
 * (N-1), generate invoice dengan snapshot pricing.
 *
 *   php artisan billing:generate-invoices [--dry-run]
 */
class GenerateBillingInvoicesCommand extends Command
{
    protected $signature = 'billing:generate-invoices
                            {--dry-run : Hitung tanpa simpan}';

    protected $description = 'Generate billing invoices untuk semua tenant aktif (jalankan setiap tanggal 5)';

    public function handle(BillingService $billingService): int
    {
        $now = now(config('app.timezone'));
        // Periode = bulan lalu (N-1)
        $periodDate = $now->copy()->subMonthNoOverflow();
        $year = $periodDate->year;
        $month = $periodDate->month;

        $dryRun = (bool) $this->option('dry-run');
        $this->info("Generating invoices for period {$year}-" . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . ($dryRun ? ' [DRY RUN]' : ''));

        $plan = Plan::where('is_active', true)->first();
        if (! $plan) {
            $this->error('No active plan found. Run seeder first.');
            return self::FAILURE;
        }

        // Tenant yang TIDAK exempt
        $tenants = Tenant::where('billing_exempt', false)->orWhereNull('billing_exempt')->get();
        $generated = 0;
        $skipped = 0;

        foreach ($tenants as $tenant) {
            // Pastikan tenant punya subscription
            $sub = $tenant->subscription;
            if (! $sub) {
                // Auto-create subscription jika belum ada (triaging default)
                $sub = Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'status' => 'trialing',
                    'trial_ends_at' => $tenant->created_at
                        ? $tenant->created_at->copy()->addMonths($plan->trial_months)
                        : $now->copy()->addMonths($plan->trial_months),
                ]);
            }

            // Skip jika masih trial
            if ($sub->isOnTrial()) {
                $skipped++;
                continue;
            }

            // Skip jika invoice periode ini sudah ada & bukan draft
            $exists = BillingCycle::where('tenant_id', $tenant->id)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->where('status', '!=', 'draft')
                ->exists();
            if ($exists) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $preview = $billingService->preview($tenant, $plan);
                $this->line("  {$tenant->name}: Rp " . number_format($preview['total_charge']));
                continue;
            }

            try {
                $cycle = $billingService->generateCycle($tenant, $sub, $year, $month);
                if ($cycle->total_charge > 0) {
                    $this->line("  ✓ {$tenant->name}: Rp " . number_format($cycle->total_charge));
                    $generated++;
                } else {
                    // Tidak ada charge, langsung mark paid (gratis)
                    $cycle->update(['status' => 'paid', 'paid_at' => $now]);
                    $this->line("  ○ {$tenant->name}: Rp 0 (no charge)");
                }
            } catch (\Throwable $e) {
                $this->error("  ✗ {$tenant->name}: {$e->getMessage()}");
                Log::error("Billing generate failed for tenant {$tenant->id}: " . $e->getMessage());
            }
        }

        $this->info("Done. Generated: {$generated}, Skipped: {$skipped}");
        return self::SUCCESS;
    }
}
