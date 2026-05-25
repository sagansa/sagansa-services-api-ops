<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use Illuminate\Http\Request;

class OrderController extends ApiController
{
    /**
     * Display a listing of orders for oversight.
     */
    public function index(Request $request)
    {
        $tenantId = $this->currentTenantId();
        
        $query = Order::where('tenant_id', $tenantId)
            ->with(['store', 'orderItems', 'orderItems.product', 'orderItems.productVariant', 'orderItems.orderItemModifications'])
            ->orderBy('created_at', 'desc');
        
        // Add optional filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('store_id')) {
            $query->where('store_id', $request->store_id);
        }
        
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        
        if ($request->has('source')) {
            $query->where('source', $request->source);
        }
        
        $orders = $query->paginate(10);

        return response()->json($orders);
    }

    /**
     * Store a newly created order.
     */
    public function store(Request $request)
    {
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
            'order_items.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'order_items.*.quantity' => 'required|integer|min:1',
            'order_items.*.unit_price' => 'required|numeric|min:0',
            'order_items.*.total_price' => 'required|numeric|min:0',
            'order_items.*.notes' => 'nullable|string',
        ]);

        $tenantId = $this->currentTenantId();
        
        // Verify that the store belongs to the current tenant
        $store = Store::where('id', $request->store_id)
            ->where('tenant_id', $tenantId)
            ->first();
        
        if (!$store) {
            return response()->json(['error' => 'Unauthorized or store not found'], 403);
        }

        try {
            \DB::beginTransaction();

            $order = Order::create(array_merge(
                $request->only([
                    'store_id', 'customer_name', 'table_code', 
                    'status', 'subtotal', 'discount_total', 
                    'tax_total', 'service_total', 'grand_total', 
                    'payment_type_id', 'source', 'device_identifier', 'is_offline'
                ]),
                [
                    'tenant_id' => $tenantId,
                    'created_by' => auth()->id() ?: $request->device_identifier
                ]
            ));

            // Create order items
            foreach ($request->order_items as $itemData) {
                $orderItem = $order->orderItems()->create([
                    'product_id' => $itemData['product_id'],
                    'product_variant_id' => $itemData['product_variant_id'] ?? null,
                    'name_snapshot' => $itemData['name_snapshot'] ?? '', // Capture name at order time
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'total_price' => $itemData['total_price'],
                    'notes' => $itemData['notes'] ?? null,
                ]);

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

            \DB::commit();

            return response()->json($order->load([
                'store', 'orderItems', 'orderItems.product', 
                'orderItems.productVariant', 'orderItems.orderItemModifications'
            ]), 201);
        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json(['error' => 'Failed to create order'], 500);
        }
    }

    /**
     * Display the specified order.
     */
    public function show(Order $order)
    {
        $tenantId = $this->currentTenantId();
        
        if ($order->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json($order->load([
            'store', 'orderItems', 'orderItems.product', 
            'orderItems.productVariant', 'orderItems.orderItemModifications',
            'orderPayments'
        ]));
    }

    /**
     * Update the specified order.
     */
    public function update(Request $request, Order $order)
    {
        $tenantId = $this->currentTenantId();
        
        if ($order->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'customer_name' => 'sometimes|string|max:255',
            'table_code' => 'sometimes|string|max:50',
            'status' => 'sometimes|in:pending,completed,cancelled,refunded',
            'payment_type_id' => 'nullable|exists:payment_type,id',
        ]);

        $order->update($request->only([
            'customer_name', 'table_code', 'status', 'payment_type_id'
        ]));

        return response()->json($order->load([
            'store', 'orderItems', 'orderItems.product', 
            'orderItems.productVariant', 'orderItems.orderItemModifications',
            'orderPayments'
        ]));
    }

    /**
     * Remove the specified order from storage.
     */
    public function destroy(Order $order)
    {
        $tenantId = $this->currentTenantId();
        
        if ($order->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $order->delete();
        return response()->json(['message' => 'Order deleted successfully']);
    }
    
    /**
     * Get order statistics for analytics.
     */
    public function analytics(Request $request)
    {
        $tenantId = $this->currentTenantId();
        
        $query = Order::where('tenant_id', $tenantId);
        
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        
        $totalOrders = $query->count();
        $totalRevenue = $query->sum('grand_total');
        $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
        
        $stats = [
            'total_orders' => $totalOrders,
            'total_revenue' => $totalRevenue,
            'average_order_value' => $avgOrderValue,
        ];
        
        return response()->json($stats);
    }
}