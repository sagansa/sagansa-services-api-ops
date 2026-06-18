<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentType;
use App\Models\Store;
use Carbon\Carbon;
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
     * Get orders for the authenticated user (used by Receipts list)
     *
     * Supported query params:
     *  - store_id    uuid     optional
     *  - status      string   optional (pending|completed|cancelled|refunded)
     *  - source      string   optional (pos|web-order)
     *  - start_date  Y-m-d    optional
     *  - end_date    Y-m-d    optional
     *  - search      string   optional (matches receipt_number, customer_name, table_code)
     *  - per_page    int      default 15
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Order::where('tenant_id', $user->tenant_id)
            ->with([
                'store:id,name,nickname',
                'orderItems' => function ($q) {
                    $q->select(['id', 'order_id', 'name_snapshot', 'quantity', 'unit_price', 'total_price'])
                        ->limit(5);
                },
            ])
            ->orderBy('created_at', 'desc');

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        if ($request->filled('start_date')) {
            $query->where('created_at', '>=', $request->start_date . ' 00:00:00');
        }

        if ($request->filled('end_date')) {
            $query->where('created_at', '<=', $request->end_date . ' 23:59:59');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('table_code', 'like', "%{$search}%")
                    ->orWhere('id', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->get('per_page', 15);

        try {
            $orders = $query->paginate($perPage);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders: ' . $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    /**
     * Get a specific order with full detail for receipt view.
     */
    public function show(Request $request, string $orderId): JsonResponse
    {
        $user = $request->user();

        try {
            $order = Order::where('id', $orderId)
                ->where('tenant_id', $user->tenant_id)
                ->with([
                    'store',
                    'paymentType',
                    'orderItems.variants',
                    'orderItems.orderItemModifications',
                    'orderPayments.paymentType',
                ])
                ->first();
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order detail: ' . $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $order,
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

    /**
     * Get sales data for bar charts: gross sales, refunds, discounts,
     * net sales, and gross profit, with grouping by day/hour/week/month.
     *
     * Definitions:
     *  - Gross Sales   = SUM(subtotal) dari order completed
     *                    (total harga sebelum discount/refund/tax/service)
     *  - Refunds       = SUM(total_refunded) dari order yang punya refund
     *                    (order tetap completed, refund dicatat di kolom
     *                    total_refunded / order_items.refund_amount)
     *  - Discounts     = SUM(discount_total) dari order completed
     *  - Net Sales     = Gross Sales − Refunds − Discounts
     *                    (pendapatan bersih setelah potongan)
     *  - Gross Profit  = Net Sales − COGS
     *                    (COGS = quantity × products.cost)
     *
     * Supported query params:
     *  - start_date (Y-m-d)  default: today
     *  - end_date   (Y-m-d)  default: today
     *  - start_hour (0-23)   optional
     *  - end_hour   (0-23)   optional
     *  - store_id   uuid     optional
     *  - created_by string   optional (employee/device identifier)
     *  - source     string   optional (pos|web-order)
     *  - group_by   string   optional (day|hour|week|month) default: day
     */
    public function chart(Request $request): JsonResponse
    {
        $user = $request->user();

        $timezone = config('app.timezone', 'Asia/Jakarta');
        $today = Carbon::today($timezone);

        $startDate = $request->get('start_date')
            ? Carbon::parse($request->get('start_date'), $timezone)->startOfDay()
            : (clone $today)->startOfDay();
        $endDate = $request->get('end_date')
            ? Carbon::parse($request->get('end_date'), $timezone)->endOfDay()
            : (clone $today)->endOfDay();

        $startHour = $request->filled('start_hour') ? (int) $request->get('start_hour') : null;
        $endHour = $request->filled('end_hour') ? (int) $request->get('end_hour') : null;

        $storeId = $request->filled('store_id') ? $request->get('store_id') : null;
        $source = $request->filled('source') ? $request->get('source') : null;
        $createdBy = $request->filled('created_by') ? $request->get('created_by') : null;

        $groupBy = in_array($request->get('group_by'), ['hour', 'day', 'week', 'month'], true)
            ? $request->get('group_by')
            : 'day';

        $conn = DB::connection('mysql_ops');

        $baseWhere = [
            ['orders.tenant_id', $user->tenant_id],
        ];

        // Determine date grouping expression
        $dateExpr = match ($groupBy) {
            'hour' => "DATE_FORMAT(orders.created_at, '%Y-%m-%d %H:00')",
            'week' => "DATE_FORMAT(orders.created_at, '%x-W%v')",
            'month' => "DATE_FORMAT(orders.created_at, '%Y-%m')",
            default => "DATE(orders.created_at)",
        };

        // Check which optional columns exist
        $hasTotalRefunded = false;
        $hasCostColumn = false;
        try {
            $hasTotalRefunded = $conn->getSchemaBuilder()->hasColumn('orders', 'total_refunded');
        } catch (\Exception $e) {
        }
        try {
            $hasCostColumn = $conn->getSchemaBuilder()->hasColumn('products', 'cost');
        } catch (\Exception $e) {
        }

        // --- Completed orders: gross sales, discounts, refunds, taxes ---
        $completedQuery = $conn->table('orders')
            ->where($baseWhere)
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$startDate, $endDate]);

        if ($startHour !== null && $endHour !== null) {
            $completedQuery->whereRaw('HOUR(orders.created_at) BETWEEN ? AND ?', [$startHour, $endHour]);
        }
        if ($storeId) $completedQuery->where('orders.store_id', $storeId);
        if ($source) $completedQuery->where('orders.source', $source);
        if ($createdBy) $completedQuery->where('orders.created_by', $createdBy);

        $refundedExpr = $hasTotalRefunded
            ? "COALESCE(SUM(orders.total_refunded), 0)"
            : "0";

        $completedRows = $completedQuery
            ->selectRaw("
                {$dateExpr} as period_key,
                COALESCE(SUM(orders.subtotal), 0) as gross_sales,
                COALESCE(SUM(orders.discount_total), 0) as discounts,
                {$refundedExpr} as refunds,
                COALESCE(SUM(orders.tax_total), 0) as taxes,
                COALESCE(SUM(orders.service_total), 0) as services,
                COUNT(*) as order_count
            ")
            ->groupBy('period_key')
            ->orderBy('period_key')
            ->get()
            ->keyBy('period_key');

        // --- COGS via order_items joined with products ---
        $costRows = collect();
        if ($hasCostColumn) {
            $costQuery = $conn->table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->leftJoin('products', 'products.id', '=', DB::raw("JSON_UNQUOTE(JSON_EXTRACT(order_items.product_snapshot, '$.id'))"))
                ->where('orders.tenant_id', $user->tenant_id)
                ->where('orders.status', 'completed')
                ->whereBetween('orders.created_at', [$startDate, $endDate])
                ->whereNotNull('order_items.product_snapshot');

            if ($startHour !== null && $endHour !== null) {
                $costQuery->whereRaw('HOUR(orders.created_at) BETWEEN ? AND ?', [$startHour, $endHour]);
            }
            if ($storeId) $costQuery->where('orders.store_id', $storeId);
            if ($source) $costQuery->where('orders.source', $source);
            if ($createdBy) $costQuery->where('orders.created_by', $createdBy);

            $costRows = $costQuery
                ->selectRaw("
                    {$dateExpr} as period_key,
                    COALESCE(SUM(order_items.quantity * COALESCE(products.cost, 0)), 0) as cogs
                ")
                ->groupBy('period_key')
                ->orderBy('period_key')
                ->get()
                ->keyBy('period_key');
        }

        // Merge all rows by period_key
        $allKeys = $completedRows->keys()
            ->merge($costRows->keys())
            ->unique()
            ->sort()
            ->values();

        $series = $allKeys->map(function ($key) use ($completedRows, $costRows) {
            $completed = $completedRows->get($key);
            $cost = $costRows->get($key);

            $grossSales = (float) ($completed->gross_sales ?? 0);
            $discounts = (float) ($completed->discounts ?? 0);
            $refunds = (float) ($completed->refunds ?? 0);
            $cogs = (float) ($cost->cogs ?? 0);

            // Net Sales = Gross Sales − Refunds − Discounts
            $netSales = $grossSales - $refunds - $discounts;

            // Gross Profit = Net Sales − COGS
            $grossProfit = $netSales - $cogs;

            return [
                'period' => $key,
                'gross_sales' => $grossSales,
                'refunds' => $refunds,
                'discounts' => $discounts,
                'net_sales' => $netSales,
                'gross_profit' => $grossProfit,
                'cogs' => $cogs,
                'order_count' => (int) ($completed->order_count ?? 0),
            ];
        })->values();

        // Totals across the whole period
        $totals = [
            'gross_sales' => (float) $series->sum('gross_sales'),
            'refunds' => (float) $series->sum('refunds'),
            'discounts' => (float) $series->sum('discounts'),
            'net_sales' => (float) $series->sum('net_sales'),
            'gross_profit' => (float) $series->sum('gross_profit'),
            'cogs' => (float) $series->sum('cogs'),
            'order_count' => (int) $series->sum('order_count'),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'timezone' => $timezone,
                ],
                'filters' => [
                    'store_id' => $storeId,
                    'source' => $source,
                    'created_by' => $createdBy,
                    'start_hour' => $startHour,
                    'end_hour' => $endHour,
                    'group_by' => $groupBy,
                ],
                'definitions' => [
                    'gross_sales' => 'SUM(subtotal) dari order completed — total harga sebelum discount/refund',
                    'refunds' => 'SUM(total_refunded) dari order yang memiliki refund',
                    'discounts' => 'SUM(discount_total) dari order completed',
                    'net_sales' => 'Gross Sales − Refunds − Discounts',
                    'gross_profit' => 'Net Sales − COGS (quantity × products.cost)',
                ],
                'totals' => $totals,
                'series' => $series,
            ],
        ]);
    }

    /**
     * Get sales summary (aggregated metrics) for the authenticated user's tenant.
     *
     * Supported query params:
     *  - start_date (Y-m-d)  default: today
     *  - end_date   (Y-m-d)  default: today
     *  - store_id   uuid      optional
     *  - source     string    optional (pos|web-order)
     *  - status     string    optional (defaults to completed when computing revenue)
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $timezone = config('app.timezone', 'Asia/Jakarta');
        $today = Carbon::today($timezone);

        $startDate = $request->get('start_date')
            ? Carbon::parse($request->get('start_date'), $timezone)->startOfDay()
            : (clone $today)->startOfDay();
        $endDate = $request->get('end_date')
            ? Carbon::parse($request->get('end_date'), $timezone)->endOfDay()
            : (clone $today)->endOfDay();

        // Use DB facade with explicit connection for reliability
        $conn = DB::connection('mysql_ops');

        // Base WHERE conditions
        $baseWhere = [
            ['tenant_id', $user->tenant_id],
        ];

        $storeId = $request->filled('store_id') ? $request->get('store_id') : null;
        $source = $request->filled('source') ? $request->get('source') : null;

        // --- Totals (completed orders only for revenue) ---
        $totalsQuery = $conn->table('orders')
            ->where($baseWhere)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate]);

        if ($storeId) {
            $totalsQuery->where('store_id', $storeId);
        }
        if ($source) {
            $totalsQuery->where('source', $source);
        }

        $totalsRow = (clone $totalsQuery)
            ->selectRaw("
                COUNT(*) as total_orders,
                COALESCE(SUM(subtotal), 0) as total_subtotal,
                COALESCE(SUM(discount_total), 0) as total_discount,
                COALESCE(SUM(tax_total), 0) as total_tax,
                COALESCE(SUM(service_total), 0) as total_service,
                COALESCE(SUM(grand_total), 0) as total_revenue
            ")
            ->first();

        $totalOrders = (int) ($totalsRow->total_orders ?? 0);
        $totalRevenue = (float) ($totalsRow->total_revenue ?? 0);
        $averageOrderValue = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

        // --- By Status (all orders, not just completed) ---
        $byStatusQuery = $conn->table('orders')
            ->where($baseWhere)
            ->whereBetween('created_at', [$startDate, $endDate]);
        if ($storeId) $byStatusQuery->where('store_id', $storeId);
        if ($source) $byStatusQuery->where('source', $source);

        $byStatusRows = (clone $byStatusQuery)
            ->selectRaw("status, COUNT(*) as count, COALESCE(SUM(grand_total), 0) as total")
            ->groupBy('status')
            ->get();

        // --- By Source ---
        $bySourceRows = (clone $byStatusQuery)
            ->selectRaw("source, COUNT(*) as count, COALESCE(SUM(grand_total), 0) as total")
            ->groupBy('source')
            ->get();

        // --- By Store ---
        $byStoreQuery = $conn->table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->where('orders.tenant_id', $user->tenant_id)
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$startDate, $endDate]);
        if ($storeId) $byStoreQuery->where('orders.store_id', $storeId);
        if ($source) $byStoreQuery->where('orders.source', $source);

        $byStoreRows = $byStoreQuery
            ->selectRaw("
                orders.store_id,
                stores.name as store_name,
                stores.nickname as store_nickname,
                COUNT(*) as count,
                COALESCE(SUM(orders.grand_total), 0) as total
            ")
            ->groupBy('orders.store_id', 'stores.name', 'stores.nickname')
            ->orderByDesc('total')
            ->get();

        // --- Top Products ---
        // Note: order_items no longer has a product_id column (dropped in
        // 2025_11_23_150402). Product info is stored in the product_snapshot
        // JSON column, so we extract id/name from there instead of joining.
        //
        // We group ONLY by product_id so the same product always aggregates
        // into a single row, even if its name differs across snapshots
        // (e.g. product was renamed). MAX() picks one representative name.
        $topProductsQuery = $conn->table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $user->tenant_id)
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->whereNotNull('order_items.product_snapshot');
        if ($storeId) $topProductsQuery->where('orders.store_id', $storeId);
        if ($source) $topProductsQuery->where('orders.source', $source);

        $topProductsRows = $topProductsQuery
            ->selectRaw("
                JSON_UNQUOTE(JSON_EXTRACT(order_items.product_snapshot, '$.id')) as product_id,
                MAX(JSON_UNQUOTE(JSON_EXTRACT(order_items.product_snapshot, '$.name'))) as product_name,
                SUM(order_items.quantity) as total_quantity,
                COALESCE(SUM(order_items.total_price), 0) as total_revenue
            ")
            ->groupBy('product_id')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get();

        // --- By Payment Method ---
        // POS orders store payment info in orders.payment_method (string) and
        // orders.payment_snapshot (JSON). We group by payment_method directly
        // since payment_type_id is typically null for POS transactions.
        $byPaymentTypeRows = collect();
        try {
            $hasPaymentMethodColumn = $conn->getSchemaBuilder()->hasColumn('orders', 'payment_method');

            if ($hasPaymentMethodColumn) {
                $byPaymentMethodQuery = $conn->table('orders')
                    ->where('tenant_id', $user->tenant_id)
                    ->where('status', 'completed')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->whereNotNull('payment_method')
                    ->where('payment_method', '!=', '');
                if ($storeId) $byPaymentMethodQuery->where('store_id', $storeId);
                if ($source) $byPaymentMethodQuery->where('source', $source);

                $byPaymentTypeRows = $byPaymentMethodQuery
                    ->selectRaw("
                        payment_method as payment_type_name,
                        COUNT(*) as order_count,
                        COALESCE(SUM(grand_total), 0) as total_amount
                    ")
                    ->groupBy('payment_method')
                    ->orderByDesc('total_amount')
                    ->get()
                    ->map(fn($row) => (object) [
                        'payment_type_id' => null,
                        'payment_type_name' => $row->payment_type_name ?? 'Unknown',
                        'order_count' => $row->order_count,
                        'total_amount' => $row->total_amount,
                    ]);
            }
        } catch (\Exception $e) {
            // If payment_method column doesn't exist or query fails, return empty
            $byPaymentTypeRows = collect();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'timezone' => $timezone,
                ],
                'filters' => [
                    'store_id' => $storeId,
                    'source' => $source,
                ],
                'totals' => [
                    'total_orders' => $totalOrders,
                    'total_subtotal' => (float) ($totalsRow->total_subtotal ?? 0),
                    'total_discount' => (float) ($totalsRow->total_discount ?? 0),
                    'total_tax' => (float) ($totalsRow->total_tax ?? 0),
                    'total_service' => (float) ($totalsRow->total_service ?? 0),
                    'total_revenue' => $totalRevenue,
                    'average_order_value' => (float) $averageOrderValue,
                ],
                'by_status' => $byStatusRows->map(fn($row) => [
                    'status' => $row->status,
                    'count' => (int) $row->count,
                    'total' => (float) $row->total,
                ])->values(),
                'by_source' => $bySourceRows->map(fn($row) => [
                    'source' => $row->source,
                    'count' => (int) $row->count,
                    'total' => (float) $row->total,
                ])->values(),
                'by_store' => $byStoreRows->map(fn($row) => [
                    'store_id' => $row->store_id,
                    'store_name' => $row->store_name,
                    'store_nickname' => $row->store_nickname,
                    'count' => (int) $row->count,
                    'total' => (float) $row->total,
                ])->values(),
                'top_products' => $topProductsRows->map(fn($row) => [
                    'product_id' => $row->product_id,
                    'product_name' => $row->product_name,
                    'total_quantity' => (int) $row->total_quantity,
                    'total_revenue' => (float) $row->total_revenue,
                ])->values(),
                'by_payment_type' => $byPaymentTypeRows->map(fn($row) => [
                    'payment_type_id' => $row->payment_type_id,
                    'payment_type_name' => $row->payment_type_name ?? 'Unknown',
                    'order_count' => (int) $row->order_count,
                    'total_amount' => (float) $row->total_amount,
                ])->values(),
            ],
        ]);
    }
}