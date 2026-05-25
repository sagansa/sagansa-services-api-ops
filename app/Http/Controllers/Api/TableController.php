<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Table::query();

        if ($request->has('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        $tables = $query->orderBy('table_number')->get();

        return response()->json([
            'success' => true,
            'data' => $tables
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'table_number' => 'required|string',
            'capacity' => 'nullable|integer|min:1',
            'is_available' => 'boolean',
        ]);

        // Check for duplicate table number in the same store
        $exists = Table::where('store_id', $request->store_id)
            ->where('table_number', $request->table_number)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Table number already exists in this store'
            ], 422);
        }

        $table = Table::create($request->all());

        return response()->json([
            'success' => true,
            'data' => $table
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $table = Table::find($id);

        if (!$table) {
            return response()->json([
                'success' => false,
                'message' => 'Table not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $table
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $table = Table::find($id);

        if (!$table) {
            return response()->json([
                'success' => false,
                'message' => 'Table not found'
            ], 404);
        }

        $request->validate([
            'table_number' => 'sometimes|string',
            'capacity' => 'nullable|integer|min:1',
            'is_available' => 'boolean',
        ]);

        if ($request->has('table_number') && $request->table_number !== $table->table_number) {
            $exists = Table::where('store_id', $table->store_id)
                ->where('table_number', $request->table_number)
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Table number already exists in this store'
                ], 422);
            }
        }

        $table->update($request->all());

        return response()->json([
            'success' => true,
            'data' => $table
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $table = Table::find($id);

        if (!$table) {
            return response()->json([
                'success' => false,
                'message' => 'Table not found'
            ], 404);
        }

        $table->delete();

        return response()->json([
            'success' => true,
            'message' => 'Table deleted successfully'
        ]);
    }
}
