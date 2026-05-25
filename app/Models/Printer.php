<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Printer extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'name',
        'connection_type', // wifi, bluetooth
        'ip_address',
        'port',
        'bluetooth_identifier',
        'is_active',
        'paper_size',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function printerJobs()
    {
        return $this->hasMany(PrinterJob::class);
    }
}