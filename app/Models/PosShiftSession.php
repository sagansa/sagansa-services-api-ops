<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosShiftSession extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_FORCE_CLOSED = 'force_closed';

    protected $table = 'pos_shift_sessions';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'opened_by_user_id',
        'closed_by_user_id',
        'opened_at',
        'closed_at',
        'business_date',
        'status',
        'opening_note',
        'closing_note',
        'is_force_closed',
        'force_closed_by_user_id',
        'force_close_reason',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'business_date' => 'date',
        'is_force_closed' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by_user_id', 'uuid');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by_user_id', 'uuid');
    }

    public function stockItems()
    {
        return $this->hasMany(PosShiftStockItem::class, 'shift_session_id');
    }

    public function movements()
    {
        return $this->hasMany(PosShiftStockMovement::class, 'shift_session_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(PosShiftAuditLog::class, 'shift_session_id');
    }

    public function forceClosedBy()
    {
        return $this->belongsTo(User::class, 'force_closed_by_user_id', 'uuid');
    }
}
