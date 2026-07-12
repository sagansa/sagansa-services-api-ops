<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan — template pricing SAGANSA (editable super-admin).
 *
 * Semua nilai tarif disimpan di sini & bisa diubah kapan saja via UI super-admin.
 * Saat invoice dibuat, snapshot harga disalin ke billing_cycles.snapshot_plan.
 */
class Plan extends Model
{
    use HasFactory;

    protected $connection = 'mysql_ops';

    protected $table = 'plans';

    protected $fillable = [
        'code',
        'name',
        'pos_rate_percent',
        'pos_base_charge',
        'pos_usage_threshold',
        'attendance_rate',
        'attendance_free_count',
        'trial_months',
        'is_active',
    ];

    protected $casts = [
        'pos_rate_percent' => 'decimal:4',
        'pos_base_charge' => 'integer',
        'pos_usage_threshold' => 'integer',
        'attendance_rate' => 'integer',
        'attendance_free_count' => 'integer',
        'trial_months' => 'integer',
        'is_active' => 'boolean',
    ];

    public function discounts(): HasMany
    {
        return $this->hasMany(PlanDiscount::class, 'plan_id', 'id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id', 'id');
    }

    /**
     * Diskon aktif yang berlaku sekarang untuk target tertentu.
     */
    public function activeDiscounts(string $appliesTo = null)
    {
        $today = now(config('app.timezone'))->toDateString();

        return $this->discounts()
            ->where('is_active', true)
            ->where('starts_at', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $today);
            })
            ->when($appliesTo, fn($q) => $q->whereIn('applies_to', [$appliesTo, 'total']))
            ->get();
    }
}
