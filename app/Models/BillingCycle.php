<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingCycle extends Model
{
    use HasFactory;

    protected $connection = 'mysql_ops';

    protected $table = 'billing_cycles';

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'plan_id',
        'period_year',
        'period_month',
        'pos_charge',
        'attendance_charge',
        'discount_amount',
        'total_charge',
        'pos_breakdown',
        'attendance_employees_count',
        'snapshot_plan',
        'status',
        'issued_at',
        'due_at',
        'paid_at',
        'payment_provider',
        'provider_invoice_id',
        'provider_invoice_url',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'period_month' => 'integer',
        'pos_charge' => 'integer',
        'attendance_charge' => 'integer',
        'discount_amount' => 'integer',
        'total_charge' => 'integer',
        'pos_breakdown' => 'array',
        'attendance_employees_count' => 'integer',
        'snapshot_plan' => 'array',
        'issued_at' => 'date',
        'due_at' => 'date',
        'paid_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class, 'billing_cycle_id', 'id');
    }

    /**
     * Scope: invoice yang sudah issued tapi belum lunas & lewat jatuh tempo.
     */
    public function scopeOverdue($query)
    {
        return $query->whereIn('status', ['issued', 'overdue'])
            ->where('due_at', '<', now(config('app.timezone'))->toDateString());
    }
}
