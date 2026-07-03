<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use HasFactory, HasUuids;

    protected $connection = 'mysql_ops';

    protected $fillable = [
        'store_id',
        'pos_shift_session_id',
        'user_id',
        'start_cash',
        'end_cash',
        'status',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'start_cash' => 'decimal:2',
        'end_cash' => 'decimal:2',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected $appends = [
        'cash_sales',
        'total_expense',
        'total_handover',
        'expected_cash',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function mutations(): HasMany
    {
        return $this->hasMany(ShiftCashMutation::class);
    }

    public function getCashSalesAttribute()
    {
        $query = Order::where('store_id', $this->store_id)
            ->where('created_by', $this->user_id)
            ->where('created_at', '>=', $this->started_at)
            ->where(function ($q) {
                $q->where('status', 'completed')
                  ->orWhereIn('payment_status', ['paid', 'completed', 'partial_refund', 'refunded']);
            });

        if ($this->ended_at) {
            $query->where('created_at', '<=', $this->ended_at);
        }

        $orders = $query->get();
        $cashSales = 0;

        foreach ($orders as $order) {
            $paymentSnapshot = $order->payment_snapshot;
            $paymentMethodName = strtolower($order->payment_method ?? $paymentSnapshot['name'] ?? '');
            $paymentType = strtolower($paymentSnapshot['type'] ?? '');

            $isCash = str_contains($paymentMethodName, 'cash') ||
                      str_contains($paymentMethodName, 'tunai') ||
                      $paymentType === 'cash';

            if ($isCash) {
                $cashSales += (float) ($order->grand_total - ($order->total_refunded ?? 0));
            }
        }

        return $cashSales;
    }

    public function getTotalExpenseAttribute()
    {
        return (float) $this->mutations()->where('type', 'expense')->sum('amount');
    }

    public function getTotalHandoverAttribute()
    {
        return (float) $this->mutations()->where('type', 'handover')->sum('amount');
    }

    public function getExpectedCashAttribute()
    {
        return (float) ($this->start_cash + $this->cash_sales - $this->total_expense - $this->total_handover);
    }
}
