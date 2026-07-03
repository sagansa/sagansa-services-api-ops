<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosShiftSession;
use App\Models\PosShiftStockItem;
use App\Models\PosShiftStockMovement;
use App\Models\PosShiftAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosShiftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PosShiftSession::with(['store', 'opener', 'closer', 'cashShift'])
            ->withCount('stockItems')
            ->orderByDesc('opened_at');

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->query('store_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('business_date')) {
            $query->whereDate('business_date', $request->query('business_date'));
        }

        $shifts = $query->limit((int) $request->query('limit', 100))->get();

        return response()->json([
            'success' => true,
            'data' => $shifts->map(fn (PosShiftSession $shift) => $this->serializeShift($shift, false))->values(),
        ]);
    }

    public function show(string $shift): JsonResponse
    {
        $session = PosShiftSession::with(['store', 'opener', 'closer', 'forceClosedBy', 'stockItems.product', 'cashShift.mutations'])
            ->findOrFail($shift);

        return response()->json([
            'success' => true,
            'data' => $this->serializeShift($session, true),
        ]);
    }

    public function forceClose(Request $request, string $shift): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', 'uuid'],
            'items.*.actual_closing_stock' => ['required_with:items', 'integer', 'min:0'],
            'items.*.closing_note' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $userId = $user?->uuid ?: $user?->id;

        $session = DB::transaction(function () use ($shift, $validated, $userId) {
            $session = PosShiftSession::query()->lockForUpdate()->findOrFail($shift);

            if ($session->status !== PosShiftSession::STATUS_OPEN) {
                return $session;
            }

            $payloadItems = collect($validated['items'] ?? [])->keyBy(fn ($item) => (string) $item['product_id']);
            $stockItems = PosShiftStockItem::where('shift_session_id', $session->id)->lockForUpdate()->get();

            foreach ($stockItems as $item) {
                $payload = $payloadItems->get((string) $item->product_id);
                $expected = (int) $item->opening_stock
                    + (int) $item->addition_stock
                    + (int) $item->adjustment_stock
                    - (int) $item->sold_quantity;
                $actual = $payload ? (int) $payload['actual_closing_stock'] : max(0, $expected);

                $item->expected_closing_stock = $expected;
                $item->actual_closing_stock = $actual;
                $item->variance = $actual - $expected;
                $item->closing_note = $payload['closing_note'] ?? $validated['reason'];
                $item->save();
            }

            $session->update([
                'status' => PosShiftSession::STATUS_FORCE_CLOSED,
                'closed_at' => now(),
                'closed_by_user_id' => $userId,
                'is_force_closed' => true,
                'force_closed_by_user_id' => $userId,
                'force_close_reason' => $validated['reason'],
                'closing_note' => $validated['reason'],
            ]);

            return $session;
        });

        return response()->json([
            'success' => true,
            'message' => 'Shift force closed.',
            'data' => $this->serializeShift($session->refresh(), true),
        ]);
    }

    public function stockVarianceReport(Request $request): JsonResponse
    {
        // Use explicit join for reliable cross-table filtering
        $query = PosShiftStockItem::query()
            ->join('pos_shift_sessions', 'pos_shift_stock_items.shift_session_id', '=', 'pos_shift_sessions.id')
            ->with(['product', 'shiftSession.store'])
            ->whereNotNull('pos_shift_stock_items.variance')
            ->orderByDesc('pos_shift_stock_items.updated_at');

        if ($storeId = $request->query('store_id')) {
            $query->where('pos_shift_sessions.store_id', $storeId);
        }

        if ($businessDate = $request->query('business_date')) {
            $query->whereDate('pos_shift_sessions.business_date', $businessDate);
        }

        // Date range filter
        if ($startDate = $request->query('start_date')) {
            $query->whereDate('pos_shift_sessions.business_date', '>=', $startDate);
        }
        if ($endDate = $request->query('end_date')) {
            $query->whereDate('pos_shift_sessions.business_date', '<=', $endDate);
        }

        $perPage = (int) $request->query('per_page', 25);
        $paginated = $query->select('pos_shift_stock_items.*')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginated->getCollection()->map(fn (PosShiftStockItem $item) => [
                'shift_id' => $item->shift_session_id,
                'business_date' => $item->shiftSession?->business_date?->toDateString(),
                'store' => $item->shiftSession?->store ? [
                    'id' => $item->shiftSession->store->id,
                    'name' => $item->shiftSession->store->name,
                    'nickname' => $item->shiftSession->store->nickname ?? null,
                ] : null,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                ] : null,
                'opening_stock' => (int) $item->opening_stock,
                'addition_stock' => (int) $item->addition_stock,
                'adjustment_stock' => (int) $item->adjustment_stock,
                'sold_quantity' => (int) $item->sold_quantity,
                'expected_closing_stock' => (int) $item->expected_closing_stock,
                'actual_closing_stock' => $item->actual_closing_stock,
                'variance' => $item->variance,
                'closing_note' => $item->closing_note,
            ])->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'total_pages' => $paginated->lastPage(),
                'total_items' => $paginated->total(),
                'per_page' => $paginated->perPage(),
            ],
        ]);
    }

    public function movements(string $shift): JsonResponse
    {
        $session = PosShiftSession::findOrFail($shift);
        $movements = PosShiftStockMovement::with(['product', 'order'])
            ->where('shift_session_id', $session->id)
            ->orderBy('created_at')
            ->get();

        return response()->json([
             'success' => true,
             'data' => $movements->map(fn (PosShiftStockMovement $m) => [
                 'id' => $m->id,
                 'product' => $m->product ? [
                     'id' => $m->product->id,
                     'name' => $m->product->name,
                 ] : null,
                 'type' => $m->type,
                 'quantity' => (int) $m->quantity,
                 'note' => $m->note,
                 'order' => $m->order ? [
                     'id' => $m->order->id,
                     'receipt_number' => $m->order->receipt_number,
                 ] : null,
                 'created_at' => $m->created_at?->toISOString(),
             ])->values(),
        ]);
    }

    public function auditLogs(string $shift): JsonResponse
    {
        $session = PosShiftSession::findOrFail($shift);
        $logs = PosShiftAuditLog::with('creator')
            ->where('shift_session_id', $session->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $logs->map(fn (PosShiftAuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'before_payload' => $l->before_payload,
                'after_payload' => $l->after_payload,
                'reason' => $l->reason,
                'created_by_user_id' => $l->created_by_user_id,
                'created_by' => $l->creator ? [
                    'id' => $l->creator->id,
                    'name' => $l->creator->name,
                ] : null,
                'created_at' => $l->created_at?->toISOString(),
            ])->values(),
        ]);
    }

    private function serializeShift(PosShiftSession $shift, bool $includeItems): array
    {
        $isOverdue = $shift->status === PosShiftSession::STATUS_OPEN
            && $shift->business_date
            && $shift->business_date->toDateString() < now()->toDateString();

        $payload = [
            'id' => $shift->id,
            'tenant_id' => $shift->tenant_id,
            'store_id' => $shift->store_id,
            'store' => $shift->store ? [
                'id' => $shift->store->id,
                'name' => $shift->store->name,
                'nickname' => $shift->store->nickname ?? null,
            ] : null,
            'opened_by_user_id' => $shift->opened_by_user_id,
            'closed_by_user_id' => $shift->closed_by_user_id,
            'opened_at' => $shift->opened_at?->toISOString(),
            'closed_at' => $shift->closed_at?->toISOString(),
            'business_date' => $shift->business_date?->toDateString(),
            'status' => $isOverdue ? 'overdue' : $shift->status,
            'raw_status' => $shift->status,
            'opening_note' => $shift->opening_note,
            'closing_note' => $shift->closing_note,
            'is_force_closed' => (bool) $shift->is_force_closed,
            'force_close_reason' => $shift->force_close_reason,
            'force_closed_by' => $shift->forceClosedBy ? [
                'id' => $shift->forceClosedBy->id,
                'name' => $shift->forceClosedBy->name,
            ] : null,
            'stock_items_count' => $shift->stock_items_count ?? $shift->stockItems->count(),
        ];

        if ($shift->relationLoaded('cashShift') && $shift->cashShift) {
            $cash = $shift->cashShift;
            $payload['cash_shift'] = [
                'id' => $cash->id,
                'start_cash' => (float) $cash->start_cash,
                'end_cash' => $cash->end_cash !== null ? (float) $cash->end_cash : null,
                'started_at' => $cash->started_at?->toISOString(),
                'ended_at' => $cash->ended_at?->toISOString(),
                'status' => $cash->status,
                'cash_sales' => (float) $cash->cash_sales,
                'total_expense' => (float) $cash->total_expense,
                'total_handover' => (float) $cash->total_handover,
                'expected_cash' => (float) $cash->expected_cash,
                'variance' => $cash->end_cash !== null ? (float) ($cash->end_cash - $cash->expected_cash) : null,
                'mutations' => $cash->relationLoaded('mutations') ? $cash->mutations->map(fn ($m) => [
                    'id' => $m->id,
                    'type' => $m->type,
                    'amount' => (float) $m->amount,
                    'note' => $m->note,
                    'created_at' => $m->created_at?->toISOString(),
                ])->values()->all() : [],
            ];
        } else {
            $payload['cash_shift'] = null;
        }

        if ($includeItems) {
            $payload['items'] = $shift->stockItems->map(fn (PosShiftStockItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'sku' => $item->product->sku,
                ] : null,
                'opening_stock' => (int) $item->opening_stock,
                'addition_stock' => (int) $item->addition_stock,
                'adjustment_stock' => (int) $item->adjustment_stock,
                'sold_quantity' => (int) $item->sold_quantity,
                'expected_closing_stock' => (int) $item->expected_closing_stock,
                'actual_closing_stock' => $item->actual_closing_stock,
                'variance' => $item->variance,
                'opening_variance' => $item->opening_variance,
                'opening_variance_note' => $item->opening_variance_note,
                'closing_note' => $item->closing_note,
            ])->values();
        }

        return $payload;
    }
}
