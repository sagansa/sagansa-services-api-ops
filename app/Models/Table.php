<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use App\Models\Store;

class Table extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'store_id',
        'table_number',
        'is_available',
        'capacity',
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'capacity' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
