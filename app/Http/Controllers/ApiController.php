<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller;

class ApiController extends Controller
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Get the tenant ID from the authenticated user
     */
    protected function currentTenantId(): string
    {
        $user = auth()->user();
        
        // If user belongs to a single tenant, return that tenant ID
        if ($user->tenants->count() === 1) {
            return $user->tenants->first()->id;
        }
        
        // This could be enhanced to use a tenant context from the request
        return $user->tenant_id; // Using the tenant_id from the user model
    }
}