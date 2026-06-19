<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Refund extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $connection = 'mysql_ops';

    /**
     * Refund type constants
     */
    public const TYPE_FULL = 'full';
    public const TYPE_PARTIAL = 'partial';

    /**
     * Refund status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'refund_number',
        'refund_type',
        'total_amount',
        'reason',
        'notes',
        'refunded_by',
        'refunded_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'payment_method',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'refunded_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refundItems(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeByOrder($query, string $orderId)
    {
        return $query->where('order_id', $orderId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('refund_type', $type);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByStore($query, string $storeId)
    {
        return $query->whereHas('order', function ($q) use ($storeId) {
            $q->where('store_id', $storeId);
        });
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFullRefund(): bool
    {
        return $this->refund_type === self::TYPE_FULL;
    }

    /**
     * Generate a unique refund number.
     * Format: REF-YYYYMMDD-XXX
     */
    public static function generateRefundNumber(): string
    {
        $date = now()->format('Ymd');
        $lastRefund = self::whereDate('created_at', today())
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastRefund && preg_match('/REF-\d{8}-(\d{3})/', $lastRefund->refund_number, $matches)) {
            $sequence = intval($matches[1]) + 1;
        } else {
            $sequence = 1;
        }

        return sprintf('REF-%s-%03d', $date, $sequence);
    }
}