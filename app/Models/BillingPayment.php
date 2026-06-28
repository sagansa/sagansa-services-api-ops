<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BillingPayment — record pembayaran masuk (dari webhook Xendit/Midtrans).
 *
 * Note: tabel `payments` (bukan `billing_payments`) per migration.
 */
class BillingPayment extends Model
{
    use HasFactory;

    protected $connection = 'mysql_ops';

    protected $table = 'payments';

    protected $fillable = [
        'billing_cycle_id',
        'amount',
        'method',
        'provider',
        'provider_payment_id',
        'paid_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function billingCycle(): BelongsTo
    {
        return $this->belongsTo(BillingCycle::class, 'billing_cycle_id', 'id');
    }
}
