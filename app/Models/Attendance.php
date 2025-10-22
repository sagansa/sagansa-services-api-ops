<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attendance extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * Attributes that may be mass assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'store_id',
        'shift_store_id',
        'status',
        'image_in',
        'check_in',
        'latitude_in',
        'longitude_in',
        'was_late',
        'image_out',
        'check_out',
        'latitude_out',
        'longitude_out',
        'auto_checked_out_at',
        'created_by_id',
        'approved_by_id',
    ];

    /**
     * Attribute casting configuration.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_in' => 'datetime',
            'check_out' => 'datetime',
            'latitude_in' => 'float',
            'longitude_in' => 'float',
            'was_late' => 'boolean',
            'latitude_out' => 'float',
            'longitude_out' => 'float',
            'auto_checked_out_at' => 'datetime',
        ];
    }

    /**
     * Available statuses for attendance approval.
     *
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
        ];
    }

    /**
     * Related store.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Related shift store configuration.
     */
    public function shiftStore(): BelongsTo
    {
        return $this->belongsTo(ShiftStore::class);
    }

    /**
     * Creator of the attendance entry.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Admin that approved or rejected the attendance entry.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }
}
