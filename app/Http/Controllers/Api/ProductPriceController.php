<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerType;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductPriceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['nullable', 'uuid', 'exists:stores,id'],
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'customer_type_id' => ['nullable', 'uuid', 'exists:customer_types,id'],
        ]);

        $query = ProductPrice::query()->with(['customerType']);

        if (! empty($validated['store_id'])) {
            $query->where('store_id', $validated['store_id']);
        }

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
            // Legacy single-pair shape: top-level store_id + product_id with
            // entries that only carry customer_type_id/price.
            'store_id' => ['sometimes', 'uuid', 'exists:stores,id'],
            'product_id' => ['sometimes', 'uuid', 'exists:products,id'],
            // Multi-pair shape: every entry carries its own store/product pair,
            // so a whole channel-pricing grid is saved in ONE request/transaction.
            'prices' => ['present', 'array'],
            'prices.*.store_id' => ['sometimes', 'uuid', 'exists:stores,id'],
            'prices.*.product_id' => ['sometimes', 'uuid', 'exists:products,id'],
            'prices.*.customer_type_id' => ['required', 'uuid', 'exists:customer_types,id'],
            'prices.*.price' => ['required', 'integer', 'min:0'],
            'prices.*.is_active' => ['sometimes', 'boolean'],
        ]);

        $entries = [];
        foreach ($validated['prices'] as $entry) {
            $storeId = $entry['store_id'] ?? $validated['store_id'] ?? null;
            $productId = $entry['product_id'] ?? $validated['product_id'] ?? null;
            abort_unless($storeId !== null && $productId !== null, 422, 'Each price entry needs a store_id and product_id.');

            $entries[] = [
                'store_id' => $storeId,
                'product_id' => $productId,
                'customer_type_id' => $entry['customer_type_id'],
                'price' => (int) $entry['price'],
                'is_active' => $entry['is_active'] ?? true,
            ];
        }

        $this->assertEntriesAssignable($entries);

        // Last write wins per unique (store, product, customer type).
        $unique = [];
        foreach ($entries as $entry) {
            $unique["{$entry['store_id']}|{$entry['product_id']}|{$entry['customer_type_id']}"] = $entry;
        }

        $prices = DB::transaction(function () use ($validated, $unique) {
            // Affected (store, product) pairs. A legacy payload with an empty
            // prices list still targets the top-level pair so a full clear works.
            $pairs = [];
            if (isset($validated['store_id'], $validated['product_id'])) {
                $pairs["{$validated['store_id']}|{$validated['product_id']}"] = [];
            }
            foreach ($unique as $entry) {
                $key = "{$entry['store_id']}|{$entry['product_id']}";
                $pairs[$key][] = $entry['customer_type_id'];
            }

            if ($unique !== []) {
                $now = now();
                $rows = [];
                foreach ($unique as $entry) {
                    $rows[] = [
                        'id' => (string) Str::uuid7(),
                        'store_id' => $entry['store_id'],
                        'product_id' => $entry['product_id'],
                        'customer_type_id' => $entry['customer_type_id'],
                        'price' => $entry['price'],
                        'is_active' => $entry['is_active'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                ProductPrice::upsert(
                    $rows,
                    ['store_id', 'product_id', 'customer_type_id'],
                    ['price', 'is_active', 'updated_at'],
                );
            }

            // For every pair in the payload, drop rows whose customer type was
            // not included (same cleanup semantics as the legacy endpoint).
            foreach ($pairs as $key => $typeIds) {
                [$storeId, $productId] = explode('|', $key);

                ProductPrice::where('store_id', $storeId)
                    ->where('product_id', $productId)
                    ->when($typeIds !== [], fn ($query) => $query->whereNotIn('customer_type_id', $typeIds))
                    ->delete();
            }

            $query = ProductPrice::query();
            foreach (array_keys($pairs) as $i => $key) {
                [$storeId, $productId] = explode('|', $key);

                $i === 0
                    ? $query->where('store_id', $storeId)->where('product_id', $productId)
                    : $query->orWhere(fn ($q) => $q->where('store_id', $storeId)->where('product_id', $productId));
            }

            return $query->get();
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
        foreach ($payload['prices'] ?? [] as $entry) {
            $type = CustomerType::findOrFail($entry['customer_type_id']);
            abort_unless($type->store_id === $store->id, 422, 'Customer type does not belong to this store.');
        }
    }

    /**
     * Batch integrity checks for bulk entries: each product must be assigned
     * to its store, and each customer type must belong to that store.
     */
    private function assertEntriesAssignable(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $products = Product::with('stores')
            ->whereIn('id', array_unique(array_column($entries, 'product_id')))
            ->get()
            ->keyBy('id');

        $types = CustomerType::whereIn('id', array_unique(array_column($entries, 'customer_type_id')))
            ->get()
            ->keyBy('id');

        foreach ($entries as $entry) {
            $product = $products->get($entry['product_id']);
            abort_unless($product !== null && $product->stores->contains('id', $entry['store_id']), 422, 'Product is not assigned to this store.');

            $type = $types->get($entry['customer_type_id']);
            abort_unless($type !== null && $type->store_id === $entry['store_id'], 422, 'Customer type does not belong to this store.');
        }
    }
}
