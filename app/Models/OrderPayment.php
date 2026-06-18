<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderPayment extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $connection = 'mysql_ops';

    protected $fillable = [
        'order_id',
        'amount',
        'payment_type_id',
        'reference',
        'captured_at',
        'is_offline',
        'synced_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'captured_at' => 'datetime',
        'synced_at' => 'datetime',
        'is_offline' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentType(): BelongsTo
    {
        return $this->belongsTo(PaymentType::class);
    }
}