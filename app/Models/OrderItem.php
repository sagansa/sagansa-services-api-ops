<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory, HasUuids;

    protected $connection = 'mysql_ops';

    protected $fillable = [
        'order_id',
        'store_id', // added for proper data segregation (api-mobile)
        'product_snapshot',
        'variant_snapshot',
        'modifications_snapshot',
        'quantity',
        'quantity_refunded',
        'unit_price',
        'total_price',
        'refund_amount',
        'notes',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'quantity' => 'integer',
        'product_snapshot' => 'array',
        'variant_snapshot' => 'array',
        'modifications_snapshot' => 'array',
    ];

    /**
     * Virtual attribute: extract product name from product_snapshot JSON
     * for backward-compatibility with consumers expecting name_snapshot.
     */
    public function getNameSnapshotAttribute(): ?string
    {
        return data_get($this->product_snapshot, 'name')
            ?? data_get($this->product_snapshot, 'product.name');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(OrderItemVariant::class);
    }

    public function orderItemModifications(): HasMany
    {
        return $this->hasMany(OrderItemModification::class);
    }

    public function refundItems(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }
}
