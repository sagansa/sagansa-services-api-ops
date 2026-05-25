<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantController extends ApiController
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $tenants = Tenant::with('owner')->paginate(10);
        return response()->json($tenants);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'owner_id' => 'required|exists:users,id',
        ]);

        try {
            DB::beginTransaction();

            $tenant = Tenant::create([
                'name' => $request->name,
                'owner_id' => $request->owner_id,
            ]);

            // Assign the owner to the tenant with admin role
            $tenant->users()->attach($request->owner_id, [
                'role' => 'admin',
                'assigned_by' => $request->user()->id
            ]);

            DB::commit();

            return response()->json($tenant->load('owner'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create tenant'], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Tenant $tenant)
    {
        return response()->json($tenant->load('owner', 'stores', 'users'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Tenant $tenant)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'owner_id' => 'sometimes|exists:users,id',
        ]);

        $tenant->update($request->only(['name', 'owner_id']));

        return response()->json($tenant->load('owner'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tenant $tenant)
    {
        $tenant->delete();
        return response()->json(['message' => 'Tenant deleted successfully']);
    }
}