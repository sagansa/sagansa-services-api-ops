<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    /**
     * Display a listing of permissions
     */
    public function index()
    {
        $permissions = Permission::where('guard_name', 'api')->get();
        
        return response()->json([
            'success' => true,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Store a newly created permission
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:permissions,name',
            'guard_name' => 'sometimes|string',
        ]);

        // Create new Permission instance and set UUID manually to bypass mass assignment protection
        $permission = new Permission();
        $permission->id = (string) Str::uuid();
        $permission->name = $request->name;
        $permission->guard_name = $request->guard_name ?? 'api';
        $permission->save();

        return response()->json([
            'success' => true,
            'message' => 'Permission created successfully',
            'permission' => $permission,
        ], 201);
    }

    /**
     * Update the specified permission
     */
    public function update(Request $request, $id)
    {
        $permission = Permission::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|unique:permissions,name,' . $id,
        ]);

        $permission->update($request->only(['name']));

        return response()->json([
            'success' => true,
            'message' => 'Permission updated successfully',
            'permission' => $permission,
        ]);
    }

    /**
     * Remove the specified permission
     */
    public function destroy($id)
    {
        $permission = Permission::findOrFail($id);
        $permission->delete();

        return response()->json([
            'success' => true,
            'message' => 'Permission deleted successfully',
        ]);
    }
}
