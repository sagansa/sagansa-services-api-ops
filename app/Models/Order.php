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

    protected $fillable = [
        'tenant_id',
        'store_id',
        'shift_session_id',
        'created_by', // user or device
        'customer_name',
        'table_code',
        'status',
        'subtotal',
        'discount_total',
        'tax_total',
        'service_total',
        'grand_total',
        'payment_type_id',
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
}
