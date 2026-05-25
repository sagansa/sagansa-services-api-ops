<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserDetail extends Model
{
    protected $connection = 'mysql_auth';
    protected $table = 'user_details';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'role',
        'is_active',
        'manager_id',
        'invitation_token',
        'invitation_token_expires_at',
        'invitation_expires_at',
        'invited_at',
        'invited_by',
        'verification_token',
    ];
}
