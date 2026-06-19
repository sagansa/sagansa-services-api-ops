<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\RefundItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RefundController extends Controller
{
    /**
     * Check whether an order is eligible for refund and return the items
     * that can still be refunded.
     */
    public function checkEligibility(Request $request, string $orderId): JsonResponse
    {
        $order = $this->loadOrderForTenant($request, $orderId);

        if (!$order) {
            return $this->notFound('Order not found');
        }

        $order->loadMissing('orderItems.refundItems.refund');

        if (!$order->isPaid()) {
            return $this->fail('Order must be paid before it can be refunded', 400);
        }

        if ($order->isFullyRefunded()) {
            return $this->fail('Order is already fully refunded', 400);
        }

        $availableItems = $order->orderItems->map(function (OrderItem $item) {
            $quantityRefunded = (int) ($item->quantity_refunded ?? 0);
            $quantityPending = (int) $item->refundItems
                ->filter(fn ($refundItem) => $refundItem->refund?->status === Refund::STATUS_PENDING)
                ->sum('quantity_refunded');
            $unitPrice = (float) ($item->unit_price ?? 0);
            $availableQty = (int) $item->quantity - $quantityRefunded - $quantityPending;

            return [
                'order_item_id' => $item->id,
                'product_name' => data_get($item->product_snapshot, 'name', 'Unknown'),
                'quantity' => (int) $item->quantity,
                'quantity_refunded' => $quantityRefunded,
                'quantity_pending_refund' => $quantityPending,
                'available_quantity' => max(0, $availableQty),
                'unit_price' => $unitPrice,
                'max_refund_amount' => max(0, $availableQty) * $unitPrice,
            ];
        })
            ->filter(fn ($item) => $item['available_quantity'] > 0)
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'order' => [
                    'id' => $order->id,
                    'receipt_number' => $order->receipt_number,
                    'grand_total' => (float) $order->grand_total,
                    'total_refunded' => (float) ($order->total_refunded ?? 0),
                    'available_refund_amount' => (float) $order->grand_total - (float) ($order->total_refunded ?? 0),
                ],
                'available_items' => $availableItems,
            ],
        ]);
    }

    /**
     * Process a refund for an order.
     *
     * Because this controller is only reachable by users with the manager
     * (or owner) role (enforced via route middleware), refunds created here
     * are completed immediately — the manager IS the approver.
     *
     * Payload:
     *   items.*.order_item_id  uuid
     *   items.*.quantity       int  (>=1)
     *   items.*.reason         string? (per-item note)
     *   reason                 string (required, overall reason)
     *   notes                  string?
     *   payment_method         string? default "cash"
     */
    public function store(Request $request, string $orderId): JsonResponse
    {
        $order = $this->loadOrderForTenant($request, $orderId);

        if (!$order) {
            return $this->notFound('Order not found');
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => 'required|uuid|exists:mysql_ops.order_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.reason' => 'nullable|string|max:500',
            'reason' => 'required|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'payment_method' => 'nullable|string|max:50',
        ]);

        if (!$order->isPaid()) {
            return $this->fail('Order must be paid before it can be refunded', 400);
        }

        if ($order->isFullyRefunded()) {
            return $this->fail('Order is already fully refunded', 400);
        }

        // Validate each item and compute totals.
        $totalRefundAmount = 0.0;
        $validatedItems = [];

        foreach ($validated['items'] as $item) {
            /** @var OrderItem|null $orderItem */
            $orderItem = OrderItem::with(['refundItems.refund'])
                ->where('id', $item['order_item_id'])
                ->where('order_id', $order->id)
                ->first();

            if (!$orderItem) {
                return $this->fail('Invalid order item', 400);
            }

            $quantityRefunded = (int) ($orderItem->quantity_refunded ?? 0);
            $quantityPending = (int) $orderItem->refundItems
                ->filter(fn ($refundItem) => $refundItem->refund?->status === Refund::STATUS_PENDING)
                ->sum('quantity_refunded');
            $availableQty = (int) $orderItem->quantity - $quantityRefunded - $quantityPending;

            if ($item['quantity'] > $availableQty) {
                return $this->fail(
                    'Quantity exceeds available refund quantity for item '
                        . data_get($orderItem->product_snapshot, 'name', 'Unknown'),
                    400,
                );
            }

            $unitPrice = (float) ($orderItem->unit_price ?? 0);
            $itemRefundAmount = $item['quantity'] * $unitPrice;
            $totalRefundAmount += $itemRefundAmount;

            $validatedItems[] = [
                'order_item' => $orderItem,
                'quantity' => (int) $item['quantity'],
                'unit_price' => $unitPrice,
                'total_refund_amount' => $itemRefundAmount,
                'reason' => $item['reason'] ?? null,
            ];
        }

        if (((float) ($order->total_refunded ?? 0) + $totalRefundAmount) > (float) $order->grand_total) {
            return $this->fail('Total refund amount exceeds order total', 400);
        }

        $user = $request->user();
        $userKey = (string) ($user->uuid ?: $user->id);
        $isFullRefund = ((float) ($order->total_refunded ?? 0) + $totalRefundAmount) >= (float) $order->grand_total;

        try {
            $refund = DB::connection('mysql_ops')->transaction(function () use ($order, $validated, $validatedItems, $totalRefundAmount, $userKey, $isFullRefund) {
                // Lock the order + items to prevent concurrent refund races.
                $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

                $refund = Refund::create([
                    'tenant_id' => $lockedOrder->tenant_id,
                    'order_id' => $lockedOrder->id,
                    'refund_number' => Refund::generateRefundNumber(),
                    'refund_type' => $isFullRefund ? Refund::TYPE_FULL : Refund::TYPE_PARTIAL,
                    'total_amount' => $totalRefundAmount,
                    'reason' => $validated['reason'],
                    'notes' => $validated['notes'] ?? null,
                    'refunded_by' => $userKey,
                    'refunded_at' => now(),
                    'approved_by' => $userKey,
                    'approved_at' => now(),
                    'payment_method' => $validated['payment_method'] ?? 'cash',
                    'status' => Refund::STATUS_COMPLETED,
                ]);

                foreach ($validatedItems as $item) {
                    /** @var OrderItem $orderItem */
                    $orderItem = $item['order_item'];

                    RefundItem::create([
                        'refund_id' => $refund->id,
                        'order_item_id' => $orderItem->id,
                        'quantity_refunded' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total_refund_amount' => $item['total_refund_amount'],
                        'reason' => $item['reason'],
                    ]);

                    // Update order item refund counters.
                    $lockedItem = OrderItem::where('id', $orderItem->id)->lockForUpdate()->first();
                    if ($lockedItem) {
                        $lockedItem->increment('quantity_refunded', $item['quantity']);
                        $lockedItem->increment('refund_amount', $item['total_refund_amount']);
                    }
                }

                // Update order-level refund totals.
                $lockedOrder->increment('total_refunded', (float) $totalRefundAmount);
                $lockedOrder->increment('refund_count');
                $lockedOrder->refresh();

                // Update payment status on order + payments.
                $nowFullyRefunded = (float) ($lockedOrder->total_refunded ?? 0) >= (float) $lockedOrder->grand_total;

                if (Schema::connection('mysql_ops')->hasColumn('orders', 'payment_status')) {
                    $lockedOrder->update([
                        'payment_status' => $nowFullyRefunded ? 'refunded' : 'partial_refund',
                    ]);
                }

                if ($nowFullyRefunded) {
                    $lockedOrder->update(['status' => 'refunded']);
                }

                if (Schema::connection('mysql_ops')->hasColumn('order_payments', 'status')) {
                    $lockedOrder->payments()->update([
                        'status' => $nowFullyRefunded ? 'refunded' : 'partial_refund',
                    ]);
                }

                return $refund->fresh(['order.store', 'refundItems.orderItem']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Refund processed successfully',
                'data' => $this->formatRefund($refund),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process refund: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List refunds. Supports filtering by order_id, store_id, status,
     * refund_type and date range.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Refund::where('tenant_id', $user->tenant_id)
            ->with(['order.store', 'refundItems.orderItem']);

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->filled('store_id')) {
            $query->whereHas('order', function ($q) use ($request) {
                $q->where('store_id', $request->store_id);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('refund_type')) {
            $query->where('refund_type', $request->refund_type);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('refunded_at', [$request->start_date, $request->end_date]);
        }

        $perPage = (int) $request->get('per_page', 15);
        $refunds = $query->orderByDesc('created_at')->paginate($perPage);
        $refunds->getCollection()->transform(fn (Refund $refund) => $this->formatRefund($refund));

        return response()->json([
            'success' => true,
            'data' => $refunds,
        ]);
    }

    /**
     * Show a single refund.
     */
    public function show(Request $request, string $refundId): JsonResponse
    {
        $user = $request->user();

        $refund = Refund::where('id', $refundId)
            ->where('tenant_id', $user->tenant_id)
            ->with(['order.store', 'refundItems.orderItem'])
            ->first();

        if (!$refund) {
            return $this->notFound('Refund not found');
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatRefund($refund),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Load an order scoped to the authenticated user's tenant.
     */
    private function loadOrderForTenant(Request $request, string $orderId): ?Order
    {
        $user = $request->user();

        return Order::where('id', $orderId)
            ->where('tenant_id', $user->tenant_id)
            ->first();
    }

    private function formatRefund(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'order_id' => $refund->order_id,
            'refund_number' => $refund->refund_number,
            'refund_type' => $refund->refund_type,
            'total_amount' => (float) $refund->total_amount,
            'reason' => $refund->reason,
            'notes' => $refund->notes,
            'status' => $refund->status,
            'payment_method' => $refund->payment_method,
            'refunded_by' => $refund->refunded_by,
            'refunded_at' => $refund->refunded_at?->toDateTimeString(),
            'approved_by' => $refund->approved_by,
            'approved_at' => $refund->approved_at?->toDateTimeString(),
            'order' => $refund->order ? [
                'id' => $refund->order->id,
                'receipt_number' => $refund->order->receipt_number,
                'store_id' => $refund->order->store_id,
                'store_name' => $refund->order->store?->nickname ?: $refund->order->store?->name,
                'grand_total' => (float) $refund->order->grand_total,
                'customer_name' => $refund->order->customer_name,
                'created_at' => $refund->order->created_at?->toDateTimeString(),
            ] : null,
            'items' => $refund->refundItems->map(function ($item) {
                return [
                    'id' => $item->id,
                    'order_item_id' => $item->order_item_id,
                    'product_name' => data_get($item->orderItem?->product_snapshot, 'name', 'Unknown'),
                    'quantity_refunded' => (int) $item->quantity_refunded,
                    'unit_price' => (float) $item->unit_price,
                    'total_refund_amount' => (float) $item->total_refund_amount,
                    'reason' => $item->reason,
                ];
            })->values(),
        ];
    }

    private function fail(string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    private function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->fail($message, 404);
    }
}