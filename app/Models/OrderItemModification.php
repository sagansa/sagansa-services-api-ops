<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemModification extends Model
{
    use HasFactory, HasUuids;

    protected $connection = 'mysql_ops';

    protected $fillable = [
        'order_item_id',
        'product_modification_id',
        'price', // snapshot price
        'quantity', // optional quantity
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function productModification(): BelongsTo
    {
        return $this->belongsTo(ProductModification::class);
    }
}