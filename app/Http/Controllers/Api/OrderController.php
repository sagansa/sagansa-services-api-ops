<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Create a new order
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $userKey = (string) ($user->uuid ?: $user->id);
        
        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'customer_name' => 'nullable|string|max:255',
            'table_code' => 'nullable|string|max:50',
            'status' => 'required|in:pending,completed,cancelled,refunded',
            'subtotal' => 'required|numeric|min:0',
            'discount_total' => 'required|numeric|min:0',
            'tax_total' => 'required|numeric|min:0',
            'service_total' => 'required|numeric|min:0',
            'grand_total' => 'required|numeric|min:0',
            'payment_type_id' => 'nullable|exists:payment_type,id',
            'source' => 'required|in:pos,web-order',
            'order_items' => 'required|array',
            'order_items.*.product_id' => 'required|exists:products,id',
            'order_items.*.variants' => 'nullable|array',
            'order_items.*.variants.*.product_variant_id' => 'required|exists:product_variants,id',
            'order_items.*.variants.*.price' => 'required|numeric|min:0',
            'order_items.*.variants.*.name' => 'required|string',
            'order_items.*.quantity' => 'required|integer|min:1',
            'order_items.*.unit_price' => 'required|numeric|min:0',
            'order_items.*.total_price' => 'required|numeric|min:0',
            'order_items.*.name_snapshot' => 'required|string',
            'order_items.*.notes' => 'nullable|string',
            'is_offline' => 'boolean',
            'device_identifier' => 'nullable|string|max:255'
        ]);

        // Verify that the store belongs to the user's tenant
        $store = Store::where('id', $request->store_id)
            ->where('tenant_id', $user->tenant_id)
            ->first();
        
        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized or store not found'
            ], 403);
        }

        try {
            DB::beginTransaction();

            $order = Order::create([
                'tenant_id' => $user->tenant_id,
                'store_id' => $request->store_id,
                'created_by' => $userKey,
                'customer_name' => $request->customer_name,
                'table_code' => $request->table_code,
                'status' => $request->status,
                'subtotal' => $request->subtotal,
                'discount_total' => $request->discount_total,
                'tax_total' => $request->tax_total,
                'service_total' => $request->service_total,
                'grand_total' => $request->grand_total,
                'payment_type_id' => $request->payment_type_id,
                'source' => $request->source,
                'is_offline' => $request->is_offline ?? false,
                'device_identifier' => $request->device_identifier,
            ]);

            // Create order items
            foreach ($request->order_items as $itemData) {
                $orderItem = $order->orderItems()->create([
                    'product_id' => $itemData['product_id'],
                    'name_snapshot' => $itemData['name_snapshot'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'total_price' => $itemData['total_price'],
                    'notes' => $itemData['notes'] ?? null,
                ]);

                // Handle variants if any
                if (isset($itemData['variants']) && is_array($itemData['variants'])) {
                    foreach ($itemData['variants'] as $variantData) {
                        $orderItem->variants()->create([
                            'product_variant_id' => $variantData['product_variant_id'],
                            'name' => $variantData['name'],
                            'price' => $variantData['price'],
                        ]);
                    }
                }

                // Handle modifications if any
                if (isset($itemData['modifications']) && is_array($itemData['modifications'])) {
                    foreach ($itemData['modifications'] as $modData) {
                        $orderItem->orderItemModifications()->create([
                            'product_modification_id' => $modData['product_modification_id'],
                            'price' => $modData['price'],
                            'quantity' => $modData['quantity'] ?? 1,
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $order->load(['orderItems', 'orderItems.product', 'orderItems.variants'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get orders for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $query = Order::where('tenant_id', $user->tenant_id)
            ->with(['orderItems', 'orderItems.product', 'orderItems.variants'])
            ->orderBy('created_at', 'desc');

        // Add optional filters
        if ($request->has('store_id')) {
            $query->where('store_id', $request->store_id);
        }
        
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('source')) {
            $query->where('source', $request->source);
        }
        
        $orders = $query->paginate($request->get('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get a specific order
     */
    public function show(Request $request, string $orderId): JsonResponse
    {
        $user = $request->user();
        
        $order = Order::where('id', $orderId)
            ->where('tenant_id', $user->tenant_id)
            ->with(['orderItems', 'orderItems.product', 'orderItems.variants', 'orderItems.orderItemModifications'])
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }

    /**
     * Update an order
     */
    public function update(Request $request, string $orderId): JsonResponse
    {
        $user = $request->user();
        
        $order = Order::where('id', $orderId)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        $request->validate([
            'status' => 'sometimes|in:pending,completed,cancelled,refunded',
            'paid_at' => 'nullable|date',
        ]);

        $order->update($request->only(['status', 'paid_at']));

        return response()->json([
            'success' => true,
            'data' => $order->load(['orderItems', 'orderItems.product', 'orderItems.variants'])
        ]);
    }
}
