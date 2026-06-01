<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerType;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariantCombination;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductPriceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'uuid', 'exists:stores,id'],
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'customer_type_id' => ['nullable', 'uuid', 'exists:customer_types,id'],
        ]);

        $query = ProductPrice::query()
            ->where('store_id', $validated['store_id'])
            ->with(['customerType', 'variant']);

        if (! empty($validated['product_id'])) {
            $query->where('product_id', $validated['product_id']);
        }

        if (! empty($validated['customer_type_id'])) {
            $query->where('customer_type_id', $validated['customer_type_id']);
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('product_id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $this->assertSameStore($validated);

        $price = ProductPrice::updateOrCreate(
            [
                'store_id' => $validated['store_id'],
                'product_id' => $validated['product_id'],
                'variant_id' => $validated['variant_id'] ?? null,
                'customer_type_id' => $validated['customer_type_id'],
            ],
            [
                'price' => (int) $validated['price'],
                'is_active' => $validated['is_active'] ?? true,
            ],
        );

        return response()->json([
            'success' => true,
            'data' => $price,
        ], 201);
    }

    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'uuid', 'exists:stores,id'],
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'prices' => ['present', 'array'],
            'prices.*.variant_id' => ['nullable', 'uuid', 'exists:product_variant_combinations,id'],
            'prices.*.customer_type_id' => ['required', 'uuid', 'exists:customer_types,id'],
            'prices.*.price' => ['required', 'integer', 'min:0'],
            'prices.*.is_active' => ['sometimes', 'boolean'],
        ]);

        $this->assertSameStore($validated);

        $prices = DB::transaction(function () use ($validated) {
            $seen = [];
            $saved = [];

            foreach ($validated['prices'] as $entry) {
                $variantId = $entry['variant_id'] ?? null;
                $key = ($variantId ?: 'base') . ':' . $entry['customer_type_id'];

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $saved[] = ProductPrice::updateOrCreate(
                    [
                        'store_id' => $validated['store_id'],
                        'product_id' => $validated['product_id'],
                        'variant_id' => $variantId,
                        'customer_type_id' => $entry['customer_type_id'],
                    ],
                    [
                        'price' => (int) $entry['price'],
                        'is_active' => $entry['is_active'] ?? true,
                    ],
                );
            }

            ProductPrice::where('store_id', $validated['store_id'])
                ->where('product_id', $validated['product_id'])
                ->whereNotIn('id', collect($saved)->pluck('id')->all())
                ->delete();

            return ProductPrice::where('store_id', $validated['store_id'])
                ->where('product_id', $validated['product_id'])
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => $prices,
        ]);
    }

    public function update(Request $request, ProductPrice $productPrice): JsonResponse
    {
        $validated = $request->validate([
            'price' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $productPrice->update($validated);

        return response()->json([
            'success' => true,
            'data' => $productPrice->refresh(),
        ]);
    }

    public function destroy(ProductPrice $productPrice): JsonResponse
    {
        $productPrice->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'store_id' => ['required', 'uuid', 'exists:stores,id'],
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'variant_id' => ['nullable', 'uuid', 'exists:product_variant_combinations,id'],
            'customer_type_id' => ['required', 'uuid', 'exists:customer_types,id'],
            'price' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function assertSameStore(array $payload): void
    {
        $store = Store::findOrFail($payload['store_id']);
        $product = Product::findOrFail($payload['product_id']);

        abort_unless($product->stores()->where('stores.id', $store->id)->exists(), 422, 'Product is not assigned to this store.');

        if (! empty($payload['customer_type_id'])) {
            $customerType = CustomerType::findOrFail($payload['customer_type_id']);
            abort_unless($customerType->store_id === $store->id, 422, 'Customer type does not belong to this store.');
        }
        if (! empty($payload['variant_id'])) {
            $variant = ProductVariantCombination::findOrFail($payload['variant_id']);
            abort_unless($variant->product_id === $product->id, 422, 'Variant does not belong to this product.');
        }

        foreach ($payload['prices'] ?? [] as $entry) {
            $type = CustomerType::findOrFail($entry['customer_type_id']);
            abort_unless($type->store_id === $store->id, 422, 'Customer type does not belong to this store.');

            if (! empty($entry['variant_id'])) {
                $variant = ProductVariantCombination::findOrFail($entry['variant_id']);
                abort_unless($variant->product_id === $product->id, 422, 'Variant does not belong to this product.');
            }
        }
    }
}
