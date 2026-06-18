<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductModification extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'product_id',
        'name',
        'price',
        'is_active',
        'linked_product_id',
        'linked_product_quantity',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'integer',
        'is_active' => 'boolean',
        'linked_product_quantity' => 'integer',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function linkedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'linked_product_id');
    }
}
