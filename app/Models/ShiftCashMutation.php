<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftCashMutation extends Model
{
    use HasFactory, HasUuids;

    protected $connection = 'mysql_ops';

    protected $table = 'shift_cash_mutations';

    protected $fillable = [
        'shift_id',
        'type', // expense, handover
        'amount',
        'note',
        'created_by', // user uuid
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }
}
