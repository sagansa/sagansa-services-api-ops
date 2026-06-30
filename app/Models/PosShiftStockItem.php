<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosShiftStockItem extends Model
{
    use HasFactory, HasUuids;

    protected $connection = 'mysql_ops';
    protected $table = 'pos_shift_stock_items';

    protected $fillable = [
        'shift_session_id',
        'product_id',
        'opening_stock',
        'addition_stock',
        'adjustment_stock',
        'sold_quantity',
        'expected_closing_stock',
        'actual_closing_stock',
        'variance',
        'opening_variance',
        'opening_variance_note',
        'closing_note',
    ];

    protected $casts = [
        'opening_stock' => 'integer',
        'addition_stock' => 'integer',
        'adjustment_stock' => 'integer',
        'sold_quantity' => 'integer',
        'expected_closing_stock' => 'integer',
        'actual_closing_stock' => 'integer',
        'variance' => 'integer',
        'opening_variance' => 'integer',
    ];

    public function shiftSession()
    {
        return $this->belongsTo(PosShiftSession::class, 'shift_session_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
