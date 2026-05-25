<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterJob extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'printer_id',
        'order_id', // nullable for test prints
        'payload', // JSON
        'status',
        'error_message',
        'attempted_at',
        'printed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempted_at' => 'datetime',
        'printed_at' => 'datetime',
    ];

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}