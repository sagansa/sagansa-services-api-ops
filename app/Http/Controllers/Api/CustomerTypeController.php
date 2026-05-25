<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CustomerTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CustomerType::query();

        if ($request->has('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        $customerTypes = $query
            ->with('linkedPaymentMethod')
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $customerTypes
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'name' => 'required|string',
            'is_active' => 'boolean',
            'order' => 'integer',
            'auto_payment' => 'boolean',
            'linked_payment_method_id' => 'nullable|exists:payment_methods,id',
        ]);

        $customerType = CustomerType::create($request->all());

        return response()->json([
            'success' => true,
            'data' => $customerType
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $customerType = CustomerType::find($id);

        if (!$customerType) {
            return response()->json([
                'success' => false,
                'message' => 'Customer type not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $customerType
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $customerType = CustomerType::find($id);

        if (!$customerType) {
            return response()->json([
                'success' => false,
                'message' => 'Customer type not found'
            ], 404);
        }

        $request->validate([
            'name' => 'sometimes|string',
            'is_active' => 'boolean',
            'order' => 'integer',
            'auto_payment' => 'boolean',
            'linked_payment_method_id' => 'nullable|exists:payment_methods,id',
        ]);

        $customerType->update($request->all());

        return response()->json([
            'success' => true,
            'data' => $customerType
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $customerType = CustomerType::find($id);

        if (!$customerType) {
            return response()->json([
                'success' => false,
                'message' => 'Customer type not found'
            ], 404);
        }

        $customerType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Customer type deleted successfully'
        ]);
    }
}
