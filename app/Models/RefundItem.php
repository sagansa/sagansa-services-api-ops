<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundItem extends Model
{
    use HasFactory, HasUuids;

    protected $connection = 'mysql_ops';

    protected $fillable = [
        'refund_id',
        'order_item_id',
        'quantity_refunded',
        'unit_price',
        'total_refund_amount',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity_refunded' => 'integer',
            'unit_price' => 'decimal:2',
            'total_refund_amount' => 'decimal:2',
        ];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}