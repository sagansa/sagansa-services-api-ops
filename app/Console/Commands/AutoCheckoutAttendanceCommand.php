<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AutoCheckoutAttendanceCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'attendance:auto-checkout';

    /**
     * The console command description.
     */
    protected $description = 'Automatically check out attendances that have passed the configured grace period.';

    public function handle(): int
    {
        $hours = (int) config('attendance.auto_checkout_after_hours', 4);
        $cutoff = CarbonImmutable::now(config('app.timezone'))->subHours(max($hours, 1));

        $attendances = Attendance::query()
            ->whereNull('check_out')
            ->whereNull('auto_checked_out_at')
            ->whereNotNull('check_in')
            ->where('check_in', '<=', $cutoff)
            ->get();

        $processed = 0;

        foreach ($attendances as $attendance) {
            $checkOutAt = $attendance->check_in?->addHours($hours) ?? $cutoff;

            $attendance->forceFill([
                'status' => Attendance::STATUS_APPROVED,
                'check_out' => $checkOutAt,
                'auto_checked_out_at' => CarbonImmutable::now(config('app.timezone')),
                'approved_by_id' => null,
            ])->save();

            $processed++;
        }

        $this->info(sprintf('Auto checked-out %d attendance record(s).', $processed));

        return self::SUCCESS;
    }
}
