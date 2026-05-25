<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'product_id',
        'product_variant_group_id',
        'name',
        'sku',
        'price',
        'stock',
        'is_active',
        'available_with_variants',
    ];

    protected $casts = [
        'price' => 'integer',
        'stock' => 'integer',
        'is_active' => 'boolean',
        'available_with_variants' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function group()
    {
        return $this->belongsTo(ProductVariantGroup::class, 'product_variant_group_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Check if this variant is available with the given selected variant IDs.
     * 
     * @param array $selectedVariantIds Array of variant IDs already selected
     * @return bool True if compatible, false otherwise
     */
    public function isAvailableWith(array $selectedVariantIds): bool
    {
        // If no restrictions set, available with everything
        if (empty($this->available_with_variants)) {
            return true;
        }

        // If restrictions exist, check if all selected variants are in the allowed list
        foreach ($selectedVariantIds as $selectedId) {
            if (!in_array($selectedId, $this->available_with_variants)) {
                return false;
            }
        }

        return true;
    }
}
