<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerType;
use App\Models\PaymentMethod;
use App\Models\ProductPrice;
use App\Models\Store;
use App\Models\StoreGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreGroupController extends Controller
{
    public function index(Request $request, string $tenantId): JsonResponse
    {
        if (! $this->canAccessTenant($request, $tenantId)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized access to tenant'], 403);
        }

        $groups = StoreGroup::where('tenant_id', $tenantId)
            ->with(['stores:id,tenant_id,store_group_id,name,nickname,status'])
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'store_groups' => $groups]);
    }

    public function store(Request $request, string $tenantId): JsonResponse
    {
        if (! $this->canAccessTenant($request, $tenantId)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to create store groups'], 403);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('store_groups', 'name')->where('tenant_id', $tenantId),
            ],
            'description' => 'nullable|string',
            'store_ids' => 'array',
            'store_ids.*' => 'uuid',
        ]);

        $group = DB::transaction(function () use ($tenantId, $validated) {
            $group = StoreGroup::create([
                'tenant_id' => $tenantId,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);

            $this->syncStores($group, $validated['store_ids'] ?? []);

            return $group;
        });

        return response()->json([
            'success' => true,
            'store_group' => $group->load('stores:id,tenant_id,store_group_id,name,nickname,status'),
        ], 201);
    }

    public function update(Request $request, string $tenantId, string $groupId): JsonResponse
    {
        if (! $this->canAccessTenant($request, $tenantId)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to update store groups'], 403);
        }

        $group = StoreGroup::where('tenant_id', $tenantId)->findOrFail($groupId);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('store_groups', 'name')->where('tenant_id', $tenantId)->ignore($group->id),
            ],
            'description' => 'nullable|string',
            'store_ids' => 'array',
            'store_ids.*' => 'uuid',
        ]);

        DB::transaction(function () use ($group, $validated) {
            $group->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);

            $this->syncStores($group, $validated['store_ids'] ?? []);
        });

        return response()->json([
            'success' => true,
            'store_group' => $group->fresh()->load('stores:id,tenant_id,store_group_id,name,nickname,status'),
        ]);
    }

    public function destroy(Request $request, string $tenantId, string $groupId): JsonResponse
    {
        if (! $this->canAccessTenant($request, $tenantId)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to delete store groups'], 403);
        }

        $group = StoreGroup::where('tenant_id', $tenantId)->findOrFail($groupId);

        DB::transaction(function () use ($group) {
            Store::where('store_group_id', $group->id)->update(['store_group_id' => null]);
            $group->delete();
        });

        return response()->json(['success' => true, 'message' => 'Store group deleted successfully']);
    }

    public function syncSettings(Request $request, string $tenantId, string $groupId): JsonResponse
    {
        if (! $this->canAccessTenant($request, $tenantId)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to sync store groups'], 403);
        }

        $validated = $request->validate([
            'source_store_id' => 'required|uuid',
        ]);

        $group = StoreGroup::where('tenant_id', $tenantId)->findOrFail($groupId);
        $sourceStore = Store::where('tenant_id', $tenantId)
            ->where('store_group_id', $group->id)
            ->findOrFail($validated['source_store_id']);

        $targetStoreIds = Store::where('tenant_id', $tenantId)
            ->where('store_group_id', $group->id)
            ->where('id', '!=', $sourceStore->id)
            ->pluck('id');

        DB::transaction(function () use ($sourceStore, $targetStoreIds) {
            $sourcePaymentMethods = PaymentMethod::where('store_id', $sourceStore->id)
                ->get();
            $sourceCustomerTypes = CustomerType::where('store_id', $sourceStore->id)
                ->orderBy('order')
                ->orderBy('name')
                ->get();
            $sourceProductPrices = ProductPrice::where('store_id', $sourceStore->id)
                ->get();
            $sourceProductRows = DB::connection('mysql_ops')
                ->table('product_store')
                ->where('store_id', $sourceStore->id)
                ->get();

            ProductPrice::whereIn('store_id', $targetStoreIds)->delete();
            CustomerType::whereIn('store_id', $targetStoreIds)->delete();
            PaymentMethod::whereIn('store_id', $targetStoreIds)
                ->where('is_default', false)
                ->delete();
            DB::connection('mysql_ops')
                ->table('product_store')
                ->whereIn('store_id', $targetStoreIds)
                ->delete();

            $now = now();
            $productRows = [];
            $paymentMethodMap = [];
            $customerTypeMap = [];

            foreach ($targetStoreIds as $targetStoreId) {
                $paymentMethodMap[$targetStoreId] = [];

                foreach ($sourcePaymentMethods as $method) {
                    if ($method->is_default) {
                        $targetDefault = PaymentMethod::where('store_id', $targetStoreId)
                            ->where('is_default', true)
                            ->where('type', $method->type)
                            ->where('name', $method->name)
                            ->first()
                            ?? PaymentMethod::where('store_id', $targetStoreId)
                                ->where('is_default', true)
                                ->where('type', $method->type)
                                ->first();

                        if ($targetDefault) {
                            $paymentMethodMap[$targetStoreId][$method->id] = $targetDefault->id;
                        }

                        continue;
                    }

                    $newMethod = PaymentMethod::create([
                        'store_id' => $targetStoreId,
                        'type' => $method->type,
                        'name' => $method->name,
                        'is_active' => $method->is_active,
                        'is_default' => false,
                        'details' => $method->details,
                        'require_proof' => $method->require_proof,
                    ]);

                    $paymentMethodMap[$targetStoreId][$method->id] = $newMethod->id;
                }

                $customerTypeMap[$targetStoreId] = [];

                foreach ($sourceCustomerTypes as $customerType) {
                    $linkedPaymentMethodId = $customerType->linked_payment_method_id
                        ? ($paymentMethodMap[$targetStoreId][$customerType->linked_payment_method_id] ?? null)
                        : null;

                    $newCustomerType = CustomerType::create([
                        'store_id' => $targetStoreId,
                        'name' => $customerType->name,
                        'is_active' => $customerType->is_active,
                        'order' => $customerType->order,
                        'auto_payment' => $customerType->auto_payment,
                        'linked_payment_method_id' => $linkedPaymentMethodId,
                    ]);

                    $customerTypeMap[$targetStoreId][$customerType->id] = $newCustomerType->id;
                }

                foreach ($sourceProductRows as $row) {
                    $productRows[] = [
                        'product_id' => $row->product_id,
                        'store_id' => $targetStoreId,
                        'price' => $row->price,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach ($sourceProductPrices as $price) {
                    $targetCustomerTypeId = $customerTypeMap[$targetStoreId][$price->customer_type_id] ?? null;

                    if (! $targetCustomerTypeId) {
                        continue;
                    }

                    ProductPrice::create([
                        'store_id' => $targetStoreId,
                        'product_id' => $price->product_id,
                        'customer_type_id' => $targetCustomerTypeId,
                        'price' => $price->price,
                        'is_active' => $price->is_active,
                    ]);
                }
            }

            if ($productRows !== []) {
                DB::connection('mysql_ops')->table('product_store')->insert($productRows);
            }
        });

        return response()->json(['success' => true, 'message' => 'Store group menu, payments, customer types, and channel pricing synced successfully']);
    }

    private function syncStores(StoreGroup $group, array $storeIds): void
    {
        Store::where('store_group_id', $group->id)->update(['store_group_id' => null]);

        if ($storeIds === []) {
            return;
        }

        Store::where('tenant_id', $group->tenant_id)
            ->whereIn('id', $storeIds)
            ->update(['store_group_id' => $group->id]);
    }

    private function canAccessTenant(Request $request, string $tenantId): bool
    {
        $user = $request->user();

        return $user && ($user->tenant_id === $tenantId || $user->hasRole('super-admin'));
    }
}
