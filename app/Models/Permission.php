<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected $connection = 'mysql_auth';
    
    protected static function boot()
    {
        parent::boot();
    }
}
