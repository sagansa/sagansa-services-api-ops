<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Product
 */
class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price' => (int) $this->price,
            'category' => $this->categoryRelation?->name,
            'stock' => (int) $this->stock,
            'is_active' => (bool) $this->is_active,
            'image_url' => $this->image_url,
            'image' => $this->image,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'request' => (bool) $this->request,
            'remaining' => (bool) $this->remaining,
            'unit_id' => $this->unit_id,
            'user_id' => $this->user_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
            'unit' => $this->whenLoaded('unit', function () {
                return [
                    'id' => $this->unit?->id,
                    'name' => $this->unit?->name,
                    'symbol' => $this->unit?->symbol,
                ];
            }),
            'category_detail' => $this->whenLoaded('categoryRelation', function () {
                return [
                    'id' => $this->categoryRelation?->id,
                    'name' => $this->categoryRelation?->name,
                ];
            }),
            'tenant' => $this->whenLoaded('tenant', function () {
                return [
                    'id' => $this->tenant?->id,
                    'name' => $this->tenant?->name,
                ];
            }),
            'stores' => $this->whenLoaded('stores', function () {
                return $this->stores
                    ->map(function ($store) {
                        return [
                            'id' => $store->id,
                            'name' => $store->name,
                            'price' => $store->pivot?->price !== null ? (int) $store->pivot->price : null,
                        ];
                    })
                    ->values();
            }),
            'store_ids' => $this->relationLoaded('stores')
                ? $this->stores->pluck('id')->values()
                : [],
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user?->uuid ?: $this->user?->id,
                    'uuid' => $this->user?->uuid,
                    'name' => $this->user?->name,
                    'email' => $this->user?->email,
                ];
            }),
            'variants' => $this->whenLoaded('variants', function () {
                return $this->variants
                    ->map(function ($variant) {
                        return [
                            'id' => $variant->id,
                            'name' => $variant->name,
                            'sku' => $variant->sku,
                            'price' => $variant->price,
                            'stock' => $variant->stock,
                            'is_active' => (bool) $variant->is_active,
                            'created_at' => $variant->created_at?->toISOString(),
                            'updated_at' => $variant->updated_at?->toISOString(),
                        ];
                    })
                    ->values();
            }),
            'variant_groups' => $this->whenLoaded('variantGroups', function () {
                return $this->variantGroups
                    ->map(function ($group) {
                        return [
                            'id' => $group->id,
                            'name' => $group->name,
                            'is_required' => (bool) $group->is_required,
                            'order' => $group->order,
                            'variants' => $group->variants->map(function ($variant) {
                                return [
                                    'id' => $variant->id,
                                    'name' => $variant->name,
                                    'sku' => $variant->sku,
                                    'price' => $variant->price,
                                    'stock' => $variant->stock,
                                    'is_active' => (bool) $variant->is_active,
                                    'product_variant_group_id' => $variant->product_variant_group_id,
                                    'available_with_variants' => $variant->available_with_variants,
                                ];
                            }),
                        ];
                    })
                    ->values();
            }),
            'variant_combinations' => $this->whenLoaded('variantCombinations', function () {
                return $this->variantCombinations
                    ->map(function ($combination) {
                        return [
                            'id' => $combination->id,
                            'product_id' => $combination->product_id,
                            'sku' => $combination->sku,
                            'price' => $combination->price,
                            'stock' => $combination->stock,
                            'is_active' => (bool) $combination->is_active,
                            'variant_ids' => $combination->variant_ids,
                            'name' => $combination->name,
                            'created_at' => $combination->created_at?->toISOString(),
                            'updated_at' => $combination->updated_at?->toISOString(),
                        ];
                    })
                    ->values();
            }),
            'modifications' => $this->whenLoaded('modifications', function () {
                return $this->modifications
                    ->map(function ($modification) {
                        return [
                            'id' => $modification->id,
                            'name' => $modification->name,
                            'price' => $modification->price,
                            'is_active' => (bool) $modification->is_active,
                            'created_at' => $modification->created_at?->toISOString(),
                            'updated_at' => $modification->updated_at?->toISOString(),
                        ];
                    })
                    ->values();
            }),
        ];
    }
}
