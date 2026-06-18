<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentType extends Model
{
    use HasUuids;
    use HasFactory;

    protected $connection = 'mysql_ops';
    protected $table = 'payment_type';
    protected $fillable = ['name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = ['created_at', 'updated_at'];


}