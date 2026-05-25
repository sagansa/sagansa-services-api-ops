<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CategoryController extends Controller
{
    /**
     * Display a listing of categories.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $query = Category::query()->orderBy('name');

        // Filter by tenant if not admin/super-admin
        if ($user && !$user->hasAnyRole(['admin', 'super-admin'])) {
            $tenantIds = $this->resolveAccessibleTenantIds($user);
            
            if (empty($tenantIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('tenant_id', $tenantIds);
            }
        }

        $categories = $query->get();

        return response()->json([
            'success' => true,
            'categories' => $categories,
        ]);
    }

    /**
     * Store a newly created category.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        
        // Auto-assign tenant_id from authenticated user
        $user = $request->user();
        if ($user && !$user->hasRole('super-admin')) {
            $data['tenant_id'] = $user->tenant_id;
        } elseif ($request->has('tenant_id')) {
            $data['tenant_id'] = $request->input('tenant_id');
        }

        $category = Category::create($data);

        return response()->json([
            'success' => true,
            'category' => $category,
        ], 201);
    }

    /**
     * Display the specified category.
     */
    public function show(string $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        return response()->json([
            'success' => true,
            'category' => $category,
        ]);
    }

    /**
     * Update the specified category.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $category->update($validator->validated());

        return response()->json([
            'success' => true,
            'category' => $category,
        ]);
    }

    /**
     * Remove the specified category (soft delete).
     */
    public function destroy(string $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        
        // Set category_id to null for all products in this category
        $category->products()->update(['category_id' => null]);
        
        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully',
        ]);
    }

    /**
     * Resolve accessible tenant IDs for the given user.
     */
    private function resolveAccessibleTenantIds($user): array
    {
        if (!$user) {
            return [];
        }

        return [$user->tenant_id];
    }
}
