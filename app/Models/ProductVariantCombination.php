<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductVariantCombination extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'price',
        'stock',
        'is_active',
        'variant_ids',
        'name',
    ];

    protected $casts = [
        'variant_ids' => 'array',
        'is_active' => 'boolean',
        'price' => 'decimal:2',
        'stock' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the product that owns this combination
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the variants that make up this combination
     */
    public function variants()
    {
        if (empty($this->variant_ids)) {
            return collect();
        }
        
        return ProductVariant::whereIn('id', $this->variant_ids)->get();
    }

    /**
     * Check if this combination matches the selected variant IDs
     */
    public function matchesSelection(array $selectedVariantIds): bool
    {
        if (empty($this->variant_ids) || empty($selectedVariantIds)) {
            return false;
        }
        
        $comboIds = collect($this->variant_ids)->sort()->values();
        $selectedIds = collect($selectedVariantIds)->sort()->values();
        
        return $comboIds->toJson() === $selectedIds->toJson();
    }
}
