<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;
    use HasUuids;

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
        'category',
        'image_path',
        'stock',
        'is_active',
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
     * Boot the model and configure slug generation.
     */
    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->slug)) {
                $product->slug = static::generateUniqueSlug($product->name);
            }
        });

        static::updating(function (Product $product) {
            if ($product->isDirty('name')) {
                $product->slug = static::generateUniqueSlug($product->name, $product->id);
            }
        });
    }

    /**
     * Generate a unique slug for the provided name.
     */
    protected static function generateUniqueSlug(string $name, ?string $ignoreId = null): string
    {
        $baseSlug = Str::slug($name) ?: Str::uuid()->toString();
        $slug = $baseSlug;
        $counter = 1;

        while (
            static::where('slug', $slug)
                ->when($ignoreId, fn ($query, $id) => $query->where('id', '!=', $id))
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /**
     * Resolve the publicly accessible image URL.
     */
    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        if (! Storage::disk('public')->exists($this->image_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->image_path);
    }
}
