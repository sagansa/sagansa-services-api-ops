<?php

namespace App\Console\Commands;

use App\Models\BillingCycle;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Suspend tenant yang invoice-nya overdue (lewat jatuh tempo, belum bayar).
 *
 * Dijalankan setiap tanggal 11 oleh scheduler.
 *   php artisan billing:suspend-overdue
 */
class SuspendOverdueTenantsCommand extends Command
{
    protected $signature = 'billing:suspend-overdue';

    protected $description = 'Suspend tenant dengan invoice overdue (jalankan setiap tanggal 11)';

    public function handle(BillingService $billingService): int
    {
        $today = now(config('app.timezone'))->toDateString();
        $this->info("Suspending overdue tenants ({$today})");

        // Cari semua billing cycle yang overdue
        $overdueCycles = BillingCycle::whereIn('status', ['issued', 'overdue'])
            ->where('due_at', '<', $today)
            ->where('total_charge', '>', 0)
            ->get();

        // Group by tenant
        $tenantIds = $overdueCycles->pluck('tenant_id')->unique();

        $suspended = 0;
        foreach ($tenantIds as $tenantId) {
            $tenant = Tenant::find($tenantId);
            if (! $tenant) {
                continue;
            }

            // Skip exempt
            if ($tenant->billing_exempt) {
                continue;
            }

            // Mark overdue cycles
            BillingCycle::where('tenant_id', $tenantId)
                ->whereIn('status', ['issued', 'overdue'])
                ->where('due_at', '<', $today)
                ->update(['status' => 'overdue']);

            // Suspend subscription
            if ($tenant->subscription) {
                $tenant->subscription->update(['status' => 'suspended']);
                $tenant->update(['subscription_status' => 'suspended']);
                $this->line("  ✓ Suspended: {$tenant->name}");
                $suspended++;
            }
        }

        $this->info("Done. Suspended: {$suspended} tenant(s)");
        Log::info("billing:suspend-overdue completed. Suspended {$suspended} tenants.");
        return self::SUCCESS;
    }
}
