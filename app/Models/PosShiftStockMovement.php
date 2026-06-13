<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosShiftStockMovement extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'pos_shift_stock_movements';

    protected $fillable = [
        'shift_session_id',
        'product_id',
        'order_id',
        'order_item_id',
        'type',
        'quantity',
        'note',
        'created_by_user_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];
}
