<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends ApiController
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $tenantId = $this->currentTenantId();
        
        $query = Product::where('tenant_id', $tenantId)
            ->with(['categoryRelation', 'unit', 'stores', 'variants', 'modifications']);
        
        // Add filtering by active status if specified
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }
        
        $products = $query->paginate(10);

        return response()->json($products);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|integer|min:0',
            'sku' => 'nullable|string|max:255|unique:products,sku',
            'barcode' => 'nullable|string|max:255|unique:products,barcode',
            'stock' => 'nullable|integer|min:0',
            'request' => 'nullable|boolean',
            'remaining' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'unit_id' => 'nullable|exists:units,id',
            'category_id' => 'nullable|exists:categories,id',
            'image' => 'nullable|image|max:2048', // Max 2MB
        ]);

        $tenantId = $this->currentTenantId();
        
        try {
            DB::beginTransaction();

            $productData = array_merge(
                $request->only([
                    'name', 'description', 'price', 'sku', 'barcode', 
                    'stock', 'request', 'remaining', 'is_active', 
                    'unit_id', 'category_id'
                ]),
                ['tenant_id' => $tenantId]
            );

            // Handle image upload if present
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('products', 'public');
                $productData['image'] = $imagePath;
            }

            $product = Product::create($productData);

            // If stores are specified, attach them to the product
            if ($request->has('store_ids') && is_array($request->store_ids)) {
                $product->stores()->attach($request->store_ids);
            }

            DB::commit();

            return response()->json($product->load([
                'categoryRelation', 
                'unit', 
                'stores', 
                'variants', 
                'modifications'
            ]), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create product'], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
        $tenantId = $this->currentTenantId();
        
        // Ensure the product belongs to the current tenant
        if ($product->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json($product->load([
            'categoryRelation', 
            'unit', 
            'stores', 
            'variants', 
            'modifications'
        ]));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        $tenantId = $this->currentTenantId();
        
        if ($product->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|integer|min:0',
            'sku' => 'nullable|string|max:255|unique:products,sku,' . $product->id,
            'barcode' => 'nullable|string|max:255|unique:products,barcode,' . $product->id,
            'stock' => 'nullable|integer|min:0',
            'request' => 'nullable|boolean',
            'remaining' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'unit_id' => 'nullable|exists:units,id',
            'category_id' => 'nullable|exists:categories,id',
            'image' => 'nullable|image|max:2048',
        ]);

        try {
            DB::beginTransaction();

            $productData = $request->only([
                'name', 'description', 'price', 'sku', 'barcode', 
                'stock', 'request', 'remaining', 'is_active', 
                'unit_id', 'category_id'
            ]);

            // Handle image upload if present
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('products', 'public');
                $productData['image'] = $imagePath;
            }

            $product->update($productData);

            // Update stores if specified
            if ($request->has('store_ids') && is_array($request->store_ids)) {
                $product->stores()->sync($request->store_ids);
            }

            DB::commit();

            return response()->json($product->load([
                'categoryRelation', 
                'unit', 
                'stores', 
                'variants', 
                'modifications'
            ]));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to update product'], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        $tenantId = $this->currentTenantId();
        
        if ($product->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $product->delete();
        return response()->json(['message' => 'Product deleted successfully']);
    }
}