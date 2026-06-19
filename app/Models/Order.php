<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $connection = 'mysql_ops';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'shift_session_id',
        'created_by', // user or device
        'customer_name',
        'table_code',
        'status',
        'payment_status',
        'subtotal',
        'discount_total',
        'tax_total',
        'service_total',
        'grand_total',
        'total_refunded',
        'refund_count',
        'payment_type_id',
        'payment_method',
        'paid_at',
        'source', // pos, web-order
        'device_identifier',
        'is_offline', // bool
        'synced_at',
        'customer_type_id',
        'proof_of_payment',
        'payment_snapshot',
        'customer_type_snapshot',
    ];

    protected $appends = [
        'receipt_number',
        'time_ago',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'store_id' => 'string',
        'shift_session_id' => 'string',
        'created_by' => 'string',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'service_total' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'payment_type_id' => 'string',
        'paid_at' => 'datetime',
        'is_offline' => 'boolean',
        'synced_at' => 'datetime',
        'payment_snapshot' => 'array',
        'customer_type_snapshot' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * User (or device) that created this order.
     * Crosses database connections (mysql_auth.users <-> mysql_ops.orders).
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function paymentType(): BelongsTo
    {
        return $this->belongsTo(PaymentType::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function orderPayments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    /**
     * Alias for orderPayments() — used by the refund flow.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Generate a receipt number — matches the api-mobile implementation
     * (first 8 chars of the order UUID) so receipts are consistent across
     * POS, mobile, and ops apps.
     */
    public function getReceiptNumberAttribute(): string
    {
        return strtoupper(substr((string) $this->id, 0, 8));
    }

    /**
     * Generate a human-readable "time ago" string (e.g. "2 hours ago").
     */
    public function getTimeAgoAttribute(): string
    {
        return $this->created_at?->diffForHumans() ?? '';
    }

    /**
     * Whether the order has been paid (i.e. has a paid_at timestamp).
     */
    public function isPaid(): bool
    {
        return !is_null($this->paid_at);
    }

    /**
     * Whether the order is fully refunded.
     */
    public function isFullyRefunded(): bool
    {
        return (float) ($this->total_refunded ?? 0) >= (float) $this->grand_total;
    }
}