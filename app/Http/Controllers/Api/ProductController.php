<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Display a listing of the products.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->orderByDesc('created_at');

        $user = $request->user();
        $includeInactive = $request->boolean('include_inactive');

        if (! $user || ! $user->hasAnyRole(['admin', 'super-admin']) || ! $includeInactive) {
            $query->where('is_active', true);
        }

        $products = $query->get();

        return response()->json([
            'success' => true,
            'products' => ProductResource::collection($products),
        ]);
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['stock'] = $data['stock'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? true;

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }

        $product = Product::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully.',
            'product' => new ProductResource($product),
        ], 201);
    }

    /**
     * Display the specified product.
     */
    public function show(Request $request, string $product): JsonResponse
    {
        $productModel = Product::query()
            ->where('id', $product)
            ->orWhere('slug', $product)
            ->firstOrFail();

        $user = $request->user();

        if (
            ! $productModel->is_active
            && (! $user || ! $user->hasAnyRole(['admin', 'super-admin']))
        ) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'product' => new ProductResource($productModel),
        ]);
    }

    /**
     * Update the specified product in storage.
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }

        $product->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'product' => new ProductResource($product->refresh()),
        ]);
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy(Product $product): JsonResponse
    {
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
        ]);
    }
}
