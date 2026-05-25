<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'image',
        'sku',
        'barcode',
        'stock',
        'request',
        'remaining',
        'is_active',
        'unit_id',
        'category_id',
        'tenant_id',
        'user_id',
    ];

    /**
     * Attribute casting.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'integer',
        'stock' => 'integer',
        'is_active' => 'boolean',
        'request' => 'boolean',
        'remaining' => 'boolean',
    ];

    /**
     * Automatically append the image URL when serialising.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'image_url',
    ];

    /**
     * Get the product's image URL.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }

    /**
     * Boot the model and configure slug generation.
     */
    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->slug)) {
                $product->slug = static::generateUniqueSlug($product->name, $product->tenant_id);
            }
            
            if (empty($product->sku)) {
                $product->sku = $product->generateSKU();
            }
        });

        static::updating(function (Product $product) {
            if ($product->isDirty('name')) {
                $product->slug = static::generateUniqueSlug($product->name, $product->tenant_id, $product->id);
            }
        });
    }

    /**
     * Generate a unique slug for the provided name, scoped by tenant.
     */
    protected static function generateUniqueSlug(string $name, ?string $tenantId, ?string $ignoreId = null): string
    {
        $baseSlug = Str::slug($name) ?: Str::uuid()->toString();
        $slug = $baseSlug;
        $counter = 1;

        while (
            static::where('slug', $slug)
                ->where('tenant_id', $tenantId)
                ->when($ignoreId, fn ($query, $id) => $query->where('id', '!=', $id))
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /**
     * Generate a unique SKU for the product.
     * Format: {TENANT_INITIAL}-{PRODUCT_INITIAL}-{RANDOM}
     */
    public function generateSKU(): string
    {
        $tenantInitial = 'XX';
        if ($this->tenant_id) {
            $tenant = Tenant::find($this->tenant_id);
            if ($tenant) {
                $tenantInitial = strtoupper(substr($tenant->name, 0, 2));
            }
        }

        $productInitial = strtoupper(substr($this->name, 0, 2));
        
        do {
            $random = rand(1000, 9999);
            $sku = "{$tenantInitial}-{$productInitial}-{$random}";
        } while (static::where('sku', $sku)->where('tenant_id', $this->tenant_id)->exists());

        return $sku;
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function categoryRelation(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class)->withPivot('price')->withTimestamps();
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function variantGroups()
    {
        return $this->hasMany(ProductVariantGroup::class)->orderBy('order');
    }

    public function variantCombinations(): HasMany
    {
        return $this->hasMany(ProductVariantCombination::class);
    }

    public function modifications(): HasMany
    {
        return $this->hasMany(ProductModification::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
