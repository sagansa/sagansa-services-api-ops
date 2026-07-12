<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    /**
     * Display a listing of the products.
     */
    public function index(Request $request): JsonResponse
    {
        \Log::info('ProductController::index called', [
            'store_id' => $request->input('store_id'),
            'user' => $request->user()?->email,
        ]);
        
        $query = Product::query()
            ->with([
                'unit',
                'categoryRelation',
                'tenant',
                'user',
                'stores',
                'variantGroups.variants',
                'variantCombinations',
                'productPrices',
                'modifications.linkedProduct',
                'bundleItems.componentProduct',
            ])
            ->orderByDesc('created_at');

        $user = $request->user();
        $includeInactive = $request->boolean('include_inactive');

        if (! $user || ! $user->hasAnyRole(['admin', 'super-admin']) || ! $includeInactive) {
            $query->where('is_active', true);
        }

        if ($user && ! $user->hasRole('super-admin') && ! $user->hasRole('admin')) {
            $tenantIds = $this->resolveAccessibleTenantIds($user);

            if (empty($tenantIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('tenant_id', $tenantIds);
            }
        }

        // Filter by store_id if provided (for POS)
        if ($request->has('store_id')) {
            $storeId = $request->input('store_id');
            \Log::info('Filtering by store_id', ['store_id' => $storeId]);
            $storeIds = $this->resolveMenuStoreIds($storeId);
            $query->whereHas('stores', function ($q) use ($storeIds) {
                $q->whereIn('stores.id', $storeIds);
            });
        }

        $products = $query->get();

        \Log::info('Products query result:', [
            'count' => $products->count(),
            'first_product' => $products->first()?->name,
            'first_product_variant_groups_count' => $products->first()?->variantGroups->count(),
            'first_product_combinations_count' => $products->first()?->variantCombinations->count(),
            'first_product_modifications_count' => $products->first()?->modifications->count(),
        ]);

        $resource = ProductResource::collection($products)->resolve();
        
        \Log::info('ProductResource result:', [
            'count' => count($resource),
            'first_has_variant_groups' => isset($resource[0]['variant_groups']),
            'first_has_combinations' => isset($resource[0]['variant_combinations']),
            'first_has_modifications' => isset($resource[0]['modifications']),
        ]);

        return response()->json([
            'success' => true,
            'products' => $resource,
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
          ->header('Pragma', 'no-cache')
          ->header('Expires', '0');
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $data['stock'] = $data['stock'] ?? 0;
        $data['request'] = isset($data['request']) ? (bool) $data['request'] : false;
        $data['remaining'] = isset($data['remaining'])
            ? (bool) $data['remaining']
            : ($data['stock'] ?? 0) > 0;
        $data['is_active'] = $data['is_active'] ?? true;
        $data['user_id'] = $data['user_id'] ?? $user?->uuid;
        $data['tenant_id'] = $data['tenant_id'] ?? $user?->tenant_id;
        $data['type'] = $data['type'] ?? 'single';
        $data['bundle_pricing_mode'] = $data['type'] === 'bundle'
            ? ($data['bundle_pricing_mode'] ?? 'fixed')
            : 'fixed';

        $this->ensureOpsTenantExists($data['tenant_id'] ?? null);
        $this->ensureOpsUserExists($user);

        if ($request->hasFile('image')) {
            $path = app(\App\Contracts\ImageStorageContract::class)->upload($request->file('image'), 'products');
            $data['image'] = $path;
        } elseif ($request->filled('image') && is_string($request->input('image'))) {
            $data['image'] = $request->input('image');
        }

        $payloadVariantGroups = $request->input('variant_groups', []);
        $payloadVariants = $request->input('variants', []);
        $payloadModifications = $request->input('modifications', []);
        $payloadBundleItems = $request->input('bundle_items', []);
        $storeAssignments = $request->input('stores');
        $storeIds = $storeAssignments ?? $request->input('store_ids');

        unset($data['stores'], $data['store_ids'], $data['variants'], $data['variant_groups'], $data['modifications'], $data['bundle_items']);

        $product = DB::transaction(function () use ($data, $payloadVariantGroups, $payloadVariants, $payloadModifications, $payloadBundleItems, $storeIds) {
            $product = Product::create($data);

            $this->syncVariantGroups($product, $payloadVariantGroups);
            $this->generateCombinations($product, $payloadVariants); // Generate combinations after variant groups
            $this->syncModifications($product, $payloadModifications);
            $this->syncBundleItems($product, $payloadBundleItems);
            $this->syncStores($product, $storeIds);

            return $product;
        });

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully.',
            'product' => (new ProductResource($product->load([
                'unit',
                'categoryRelation',
                'tenant',
                'user',
                'stores',
                'variantGroups.variants',
                'variantCombinations',
                'productPrices',
                'modifications.linkedProduct',
                'bundleItems.componentProduct',
            ])))->resolve(),
        ], 201);
    }

    /**
     * Display the specified product.
     */
    public function show(Request $request, string $product): JsonResponse
    {
        $productModel = Product::query()
            ->with([
                'unit',
                'categoryRelation',
                'tenant',
                'user',
                'stores',
                'variantGroups.variants',
                'variantCombinations',
                'productPrices',
                'modifications.linkedProduct',
                'bundleItems.componentProduct',
            ])
            ->where('id', $product)
            ->orWhere('slug', $product)
            ->firstOrFail();

        $user = $request->user();

        if (
            ! $productModel->is_active
            && (! $user || ! $user->hasAnyRole(['admin', 'super-admin']))
        ) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'product' => (new ProductResource($productModel))->resolve(),
        ]);
    }

    /**
     * Update the specified product in storage.
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();

        if ($request->boolean('remove_image')) {
            if ($product->image) {
                app(\App\Contracts\ImageStorageContract::class)->delete($product->image);
            }
            $data['image'] = null;
        } elseif ($request->hasFile('image')) {
            if ($product->image) {
                app(\App\Contracts\ImageStorageContract::class)->delete($product->image);
            }
            $path = app(\App\Contracts\ImageStorageContract::class)->upload($request->file('image'), 'products');
            $data['image'] = $path;
        } elseif ($request->filled('image') && is_string($request->input('image'))) {
            if ($product->image && $product->image !== $request->input('image')) {
                app(\App\Contracts\ImageStorageContract::class)->delete($product->image);
            }
            $data['image'] = $request->input('image');
        }

        if (array_key_exists('request', $data)) {
            $data['request'] = (bool) $data['request'];
        }

        if (array_key_exists('remaining', $data)) {
            $data['remaining'] = (bool) $data['remaining'];
        }

        $payloadVariantGroups = $request->has('variant_groups') ? $request->input('variant_groups', []) : null;
        $payloadVariants = $request->has('variants') ? $request->input('variants', []) : null;
        $payloadModifications = $request->has('modifications') ? $request->input('modifications', []) : null;
        $payloadBundleItems = $request->has('bundle_items') ? $request->input('bundle_items', []) : null;
        $storeAssignments = $request->has('stores') ? $request->input('stores') : null;
        $storeIds = $storeAssignments ?? ($request->has('store_ids') ? $request->input('store_ids') : null);

        if (($data['type'] ?? $product->type ?? 'single') !== 'bundle') {
            $data['bundle_pricing_mode'] = 'fixed';
        } else {
            $data['bundle_pricing_mode'] = $data['bundle_pricing_mode'] ?? $product->bundle_pricing_mode ?? 'fixed';
        }

        unset($data['stores'], $data['store_ids'], $data['variants'], $data['variant_groups'], $data['modifications'], $data['bundle_items']);

        $product = DB::transaction(function () use ($product, $data, $payloadVariantGroups, $payloadVariants, $payloadModifications, $payloadBundleItems, $storeIds) {
            $product->update($data);

            if ($payloadVariantGroups !== null) {
                $this->syncVariantGroups($product, $payloadVariantGroups);
                \Log::info('Calling generateCombinations with variants:', ['count' => count($payloadVariants ?? [])]);
                $this->generateCombinations($product, $payloadVariants); // Regenerate combinations when variant groups change
            }

            if ($payloadModifications !== null) {
                $this->syncModifications($product, $payloadModifications);
            }

            if ($payloadBundleItems !== null || ($product->type ?? 'single') !== 'bundle') {
                $this->syncBundleItems($product, $payloadBundleItems ?? []);
            }

            if ($storeIds !== null) {
                $this->syncStores($product, $storeIds);
            }

            return $product;
        });

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'product' => (new ProductResource($product->refresh()->load([
                'unit',
                'categoryRelation',
                'tenant',
                'user',
                'stores',
                'variantGroups.variants',
                'variantCombinations',
                'productPrices',
                'modifications.linkedProduct',
                'bundleItems.componentProduct',
            ])))->resolve(),
        ]);
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy(Product $product): JsonResponse
    {
        $product->load([
            'unit',
            'categoryRelation',
            'tenant',
            'user',
            'stores',
            'variantGroups.variants',
            'variantCombinations',
            'modifications.linkedProduct',
            'bundleItems.componentProduct',
        ]);

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
            'product' => (new ProductResource($product))->resolve(),
        ]);
    }

    /**
     * Resolve the tenant IDs accessible by the authenticated user.
     */
    private function resolveAccessibleTenantIds($user): array
    {
        $tenantIds = collect();

        if (! empty($user->tenant_id)) {
            $tenantIds->push($user->tenant_id);
        }

        if ($user->ownedTenant) {
            $tenantIds->push($user->ownedTenant->id);
        }

        $membershipTenantIds = $user->tenants()->pluck('tenants.id');

        return $tenantIds
            ->merge($membershipTenantIds)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function ensureOpsTenantExists(?string $tenantId): void
    {
        if (!$tenantId) {
            return;
        }

        $tenantExists = DB::connection('mysql_ops')
            ->table('tenants')
            ->where('id', $tenantId)
            ->exists();

        if (! $tenantExists) {
            throw new \RuntimeException('Tenant not found in sagansa_ops.');
        }
    }

    private function ensureOpsUserExists(?User $user): void
    {
        if (!$user?->uuid) {
            return;
        }

        if (! Schema::connection('mysql_ops')->hasTable('users')) {
            return;
        }

        DB::connection('mysql_ops')
            ->table('users')
            ->updateOrInsert(
                ['id' => $user->uuid],
                [
                    'name' => $user->name,
                    'email' => $user->email,
                    'password' => $user->getAuthPassword(),
                    'email_verified_at' => $user->email_verified_at,
                    'is_active' => $user->is_active,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
    }

    private function syncVariantGroups(Product $product, $groups): void
    {
        if (is_string($groups)) {
            $decoded = json_decode($groups, true);
            $groups = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($groups)) {
            $groups = [];
        }

        // Delete existing groups (and cascades to variants)
        // In a real app, you might want to be smarter about updates to preserve IDs
        $product->variantGroups()->delete();

        if ($groups === []) {
            return;
        }

        foreach ($groups as $index => $groupData) {
            if (empty($groupData['name'])) continue;

            $group = $product->variantGroups()->create([
                'name' => $groupData['name'],
                'is_required' => $groupData['is_required'] ?? false,
                'order' => $groupData['order'] ?? $index,
            ]);

            if (!empty($groupData['variants']) && is_array($groupData['variants'])) {
                $variants = collect($groupData['variants'])
                    ->filter(fn ($v) => !empty($v['name']))
                    ->map(function ($v, $variantIndex) use ($product) {
                        return [
                            'product_id' => $product->id,
                            'name' => $v['name'],
                            'sku' => isset($v['sku']) && $v['sku'] !== '' ? $v['sku'] : null,
                            'price' => isset($v['price']) ? (int) $v['price'] : null,
                            'stock' => isset($v['stock']) ? (int) $v['stock'] : 0,
                            'is_active' => array_key_exists('is_active', $v) ? (bool) $v['is_active'] : true,
                            'sort_order' => array_key_exists('sort_order', $v) ? (int) $v['sort_order'] : (int) $variantIndex,
                            'available_with_variants' => $v['available_with_variants'] ?? null,
                        ];
                    })
                    ->values()
                    ->all();

                if (!empty($variants)) {
                    $group->variants()->createMany($variants);
                }
            }
        }
    }

    private function syncModifications(Product $product, $modifications): void
    {
        if (is_string($modifications)) {
            $decoded = json_decode($modifications, true);
            $modifications = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($modifications)) {
            $modifications = [];
        }

        if ($modifications === []) {
            $product->modifications()->delete();
            return;
        }

        $product->modifications()->delete();

        $schema = $product->getConnection()->getSchemaBuilder();
        $supportsLinkedProducts = $schema->hasColumn('product_modifications', 'linked_product_id')
            && $schema->hasColumn('product_modifications', 'linked_product_quantity');

        $prepared = collect($modifications)
            ->filter(fn ($modification) => ! empty($modification['name']))
            ->map(function ($modification, $index) use ($product, $supportsLinkedProducts) {
                $data = [
                    'name' => $modification['name'],
                    'price' => isset($modification['price']) ? (int) $modification['price'] : 0,
                    'is_active' => array_key_exists('is_active', $modification)
                        ? (bool) $modification['is_active']
                        : true,
                    'sort_order' => array_key_exists('sort_order', $modification)
                        ? (int) $modification['sort_order']
                        : (int) $index,
                ];

                if ($supportsLinkedProducts) {
                    $data += [
                        'linked_product_id' => ! empty($modification['linked_product_id'])
                            && (string) $modification['linked_product_id'] !== (string) $product->id
                            ? (string) $modification['linked_product_id']
                            : null,
                        'linked_product_quantity' => ! empty($modification['linked_product_id'])
                            ? max(1, (int) ($modification['linked_product_quantity'] ?? 1))
                            : null,
                    ];
                }

                return $data;
            })
            ->values()
            ->all();

        if ($prepared !== []) {
            $linkedProductIds = collect($prepared)
                ->pluck('linked_product_id')
                ->filter()
                ->unique()
                ->values();

            if ($supportsLinkedProducts && $linkedProductIds->isNotEmpty()) {
                $validLinkedProductIds = Product::query()
                    ->where('tenant_id', $product->tenant_id)
                    ->whereIn('id', $linkedProductIds->all())
                    ->where('id', '!=', $product->id)
                    ->where(function ($query) {
                        $query->where('type', 'single')
                            ->orWhereNull('type');
                    })
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->all();

                if (count($validLinkedProductIds) !== $linkedProductIds->count()) {
                    throw ValidationException::withMessages([
                        'modifications' => ['Linked modification products must be single products from the same tenant.'],
                    ]);
                }
            }

            $product->modifications()->createMany($prepared);
        }
    }

    private function syncBundleItems(Product $product, $bundleItems): void
    {
        if (is_string($bundleItems)) {
            $decoded = json_decode($bundleItems, true);
            $bundleItems = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($bundleItems)) {
            $bundleItems = [];
        }

        if (($product->type ?? 'single') !== 'bundle') {
            $product->bundleItems()->delete();
            return;
        }

        $prepared = collect($bundleItems)
            ->filter(fn ($item) => ! empty($item['component_product_id']))
            ->map(function ($item, int $index) {
                return [
                    'component_product_id' => (string) $item['component_product_id'],
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'sort_order' => (int) ($item['sort_order'] ?? $index),
                ];
            })
            ->filter(fn ($item) => $item['component_product_id'] !== (string) $product->id)
            ->unique('component_product_id')
            ->values();

        if ($prepared->isEmpty()) {
            $product->bundleItems()->delete();
            return;
        }

        $componentIds = $prepared->pluck('component_product_id')->all();
        $validComponentIds = Product::query()
            ->where('tenant_id', $product->tenant_id)
            ->whereIn('id', $componentIds)
            ->where('id', '!=', $product->id)
            ->where(function ($query) {
                $query->where('type', 'single')
                    ->orWhereNull('type');
            })
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (count($validComponentIds) !== count($componentIds)) {
            throw ValidationException::withMessages([
                'bundle_items' => ['Bundle components must be single products from the same tenant.'],
            ]);
        }

        $product->bundleItems()->delete();
        $product->bundleItems()->createMany(
            $prepared
                ->map(fn ($item) => [
                    'bundle_product_id' => $product->id,
                    'component_product_id' => $item['component_product_id'],
                    'quantity' => $item['quantity'],
                    'sort_order' => $item['sort_order'],
                ])
                ->all()
        );
    }

    private function syncStores(Product $product, $storeAssignments): void
    {
        if (is_null($storeAssignments)) {
            return;
        }

        // Debug logging
        \Log::info('syncStores called with:', ['storeAssignments' => $storeAssignments]);

        if (is_string($storeAssignments)) {
            $decoded = json_decode($storeAssignments, true);
            $storeAssignments = is_array($decoded) ? $decoded : [];
            \Log::info('Decoded JSON:', ['storeAssignments' => $storeAssignments]);
        }

        if (! is_array($storeAssignments)) {
            $storeAssignments = [];
        }

        $syncData = collect($storeAssignments)
            ->map(function ($entry) use ($product) {
                if (is_array($entry)) {
                    $storeId = $entry['id'] ?? $entry['store_id'] ?? $entry['storeId'] ?? null;
                    if (! $storeId) {
                        return null;
                    }

                    $price = $entry['price'] ?? null;
                    $stock = $entry['stock'] ?? null;
                    \Log::info('Processing store entry:', ['entry' => $entry, 'price_raw' => $price]);
                    
                    // If price is null, empty string, or 0, use product default price
                    if ($price === '' || $price === null || $price === 0 || $price === '0') {
                        $price = $product->price;
                    } else {
                        $price = (int) round((float) $price);
                    }

                    return [
                        'id' => (string) $storeId,
                        'price' => $price,
                        'stock' => ($stock === '' || $stock === null)
                            ? null
                            : max(0, (int) $stock),
                    ];
                }

                if ($entry === null || $entry === '') {
                    return null;
                }

                return [
                    'id' => (string) $entry,
                    'price' => $product->price, // Use product default price
                    'stock' => null,
                ];
            })
            ->filter()
            ->unique('id')
            ->values()
            ->all();

        \Log::info('Final syncData:', ['syncData' => $syncData]);

        $supportsStoreStock = Schema::connection('mysql_ops')->hasColumn('product_store', 'stock');

        $product->stores()->sync(collect($syncData)->mapWithKeys(function ($row) use ($supportsStoreStock) {
            $pivot = ['price' => $row['price']];

            if ($supportsStoreStock) {
                $pivot['stock'] = $row['stock'];
            }

            return [
                $row['id'] => $pivot,
            ];
        })->all());
    }

    private function resolveMenuStoreIds(string $storeId): array
    {
        $store = Store::select(['id', 'tenant_id', 'store_group_id'])->find($storeId);

        if (! $store || ! $store->store_group_id) {
            return [$storeId];
        }

        return Store::where('tenant_id', $store->tenant_id)
            ->where('store_group_id', $store->store_group_id)
            ->pluck('id')
            ->push($storeId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Generate all possible combinations from variant groups (Cartesian product)
     */
    private function generateCombinations(Product $product, ?array $payloadCombinations = null): void
    {
        \Log::info('generateCombinations started', ['payload_count' => count($payloadCombinations ?? [])]);

        // Delete existing combinations
        $product->variantCombinations()->delete();
        
        // Load variant groups with variants
        $variantGroups = $product->variantGroups()->orderBy('order')->with('variants')->get();
        
        // If no variant groups, nothing to generate
        if ($variantGroups->isEmpty()) {
            return;
        }
        
        // Get arrays of variant IDs per group
        $groupedVariantIds = $variantGroups->map(function ($group) {
            return $group->variants->pluck('id')->toArray();
        })->filter(fn($ids) => !empty($ids))->values()->all();
        
        // If any group has no variants, skip combination generation
        if (empty($groupedVariantIds)) {
            return;
        }
        
        // Generate cartesian product of all variant IDs
        $combinations = $this->cartesianProduct($groupedVariantIds);
        
        // Create combination records
        foreach ($combinations as $variantIdCombo) {
            // Get variant details for display name
            // We need to maintain the order of groups to ensure consistent naming
            // The $variantIdCombo is already in the order of $groupedVariantIds (which is group order)
            
            // Fetch all variants at once to avoid N+1, but we need to map them back to the order
            $variants = \App\Models\ProductVariant::whereIn('id', $variantIdCombo)->get();
            
            // Reorder variants based on the order in $variantIdCombo
            $orderedVariants = collect($variantIdCombo)->map(function ($id) use ($variants) {
                return $variants->firstWhere('id', $id);
            });
            
            $name = $orderedVariants->pluck('name')->join(' × ');
            \Log::info("Generated combination name: '$name'");
            
            $price = (int) $product->price + $orderedVariants->sum(fn ($variant) => (int) ($variant?->price ?? 0));
            $stock = 0;
            $sku = null;
            $isActive = true;
            
            if ($payloadCombinations) {
                // Find matching combination in payload by name
                $match = collect($payloadCombinations)->first(function ($combo) use ($name) {
                    $payloadName = $combo['name'] ?? 'UNKNOWN';
                    return $payloadName === $name;
                });
                
                if ($match) {
                    $price = isset($match['price']) ? (int)$match['price'] : $price;
                    $stock = isset($match['stock']) ? (int)$match['stock'] : $stock;
                    $sku = isset($match['sku']) && $match['sku'] !== '' ? $match['sku'] : null;
                    $isActive = array_key_exists('is_active', $match) ? (bool)$match['is_active'] : $isActive;
                }
            }
            
            \App\Models\ProductVariantCombination::create([
                'product_id' => $product->id,
                'variant_ids' => $variantIdCombo,
                'name' => $name,
                'price' => $price,
                'stock' => $stock,
                'sku' => $sku,
                'is_active' => $isActive,
            ]);
        }
    }

    /**
     * Calculate Cartesian product of arrays
     * 
     * @param array $arrays Array of arrays to combine
     * @return array Array of all possible combinations
     */
    private function cartesianProduct(array $arrays): array
    {
        $result = [[]];
        
        foreach ($arrays as $key => $values) {
            $temp = [];
            foreach ($result as $resultItem) {
                foreach ($values as $value) {
                    $temp[] = array_merge($resultItem, [$value]);
                }
            }
            $result = $temp;
        }
        
        return $result;
    }
}
