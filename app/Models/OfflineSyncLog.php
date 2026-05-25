<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OfflineSyncLog extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'device_identifier',
        'payload_type',
        'payload_id',
        'status',
        'synced_at',
        'error_details',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];
}