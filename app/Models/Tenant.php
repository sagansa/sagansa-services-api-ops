<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $connection = 'mysql_ops';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'owner_id',
        'operation_mode',
        'foodcourt_config',
        'billing_exempt',
        'subscription_status',
    ];

    protected $casts = [
        'foodcourt_config' => 'array',
        'billing_exempt' => 'boolean',
    ];

    /**
     * Users that belong to the tenant.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, $this->opsTable('tenant_user'), 'tenant_id', 'user_id', 'id', 'uuid')
            ->withPivot(['role', 'assigned_by'])
            ->withTimestamps();
    }

    /**
     * Owner of the tenant.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id', 'uuid');
    }

    /**
     * Stores registered under the tenant.
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    /**
     * Shift configurations associated with the tenant.
     */
    public function shiftStores(): HasMany
    {
        return $this->hasMany(ShiftStore::class);
    }

    /**
     * Printers registered under the tenant.
     */
    public function printers(): HasMany
    {
        return $this->hasMany(Printer::class);
    }

    /**
     * Orders associated with the tenant.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Offline sync logs for the tenant.
     */
    public function offlineSyncLogs(): HasMany
    {
        return $this->hasMany(OfflineSyncLog::class);
    }

    /**
     * Subscription billing tenant (1 per tenant).
     */
    public function subscription()
    {
        return $this->hasOne(\App\Models\Subscription::class, 'tenant_id', 'id');
    }

    /**
     * Billing cycles / invoices for the tenant.
     */
    public function billingCycles(): HasMany
    {
        return $this->hasMany(\App\Models\BillingCycle::class, 'tenant_id', 'id');
    }

    private function authTable(string $table): string
    {
        $database = config('database.connections.mysql_auth.database');

        return $database ? "{$database}.{$table}" : $table;
    }

    private function opsTable(string $table): string
    {
        $database = config('database.connections.mysql_ops.database');

        return $database ? "{$database}.{$table}" : $table;
    }
}
