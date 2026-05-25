<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Models\Store;
use App\Models\PrinterJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrinterController extends Controller
{
    /**
     * Get printers for the authenticated user's stores
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $storeId = $request->query('store_id');
        
        $query = Printer::where('tenant_id', $user->tenant_id)
            ->select(['id', 'store_id', 'name', 'connection_type', 'ip_address', 'port', 'bluetooth_identifier', 'is_active', 'paper_size'])
            ->with('store:id,name,nickname');
        
        if ($storeId) {
            $query->where('store_id', $storeId);
        }
        
        $printers = $query->get();

        return response()->json([
            'success' => true,
            'data' => $printers
        ]);
    }

    /**
     * Get a specific printer
     */
    public function show(Request $request, string $printerId): JsonResponse
    {
        $user = $request->user();
        
        $printer = Printer::where('id', $printerId)
            ->where('tenant_id', $user->tenant_id)
            ->select(['id', 'store_id', 'name', 'connection_type', 'ip_address', 'port', 'bluetooth_identifier', 'is_active', 'paper_size'])
            ->with('store:id,name,nickname')
            ->first();

        if (!$printer) {
            return response()->json([
                'success' => false,
                'message' => 'Printer not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $printer
        ]);
    }

    /**
     * Register or update a printer
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'name' => 'required|string|max:255',
            'connection_type' => 'required|in:wifi,bluetooth',
            'is_active' => 'required|boolean',
            'paper_size' => 'required|string|max:20',
            'ip_address' => 'required_if:connection_type,wifi|nullable|ip',
            'port' => 'required_if:connection_type,wifi|nullable|integer|min:1|max:65535',
            'bluetooth_identifier' => 'required_if:connection_type,bluetooth|nullable|string|max:255',
        ]);

        // Verify that the store belongs to the user's tenant
        $store = Store::where('id', $request->store_id)
            ->where('tenant_id', $user->tenant_id)
            ->first();
        
        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized or store not found'
            ], 403);
        }

        $printer = Printer::create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $request->store_id,
            'name' => $request->name,
            'connection_type' => $request->connection_type,
            'ip_address' => $request->ip_address,
            'port' => $request->port,
            'bluetooth_identifier' => $request->bluetooth_identifier,
            'is_active' => $request->is_active,
            'paper_size' => $request->paper_size,
        ]);

        return response()->json([
            'success' => true,
            'data' => $printer->load('store:id,name,nickname')
        ], 201);
    }

    /**
     * Update a printer
     */
    public function update(Request $request, string $printerId): JsonResponse
    {
        $user = $request->user();
        
        $printer = Printer::where('id', $printerId)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (!$printer) {
            return response()->json([
                'success' => false,
                'message' => 'Printer not found'
            ], 404);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'connection_type' => 'sometimes|in:wifi,bluetooth',
            'is_active' => 'sometimes|boolean',
            'paper_size' => 'sometimes|string|max:20',
            'ip_address' => 'required_if:connection_type,wifi|nullable|ip',
            'port' => 'required_if:connection_type,wifi|nullable|integer|min:1|max:65535',
            'bluetooth_identifier' => 'required_if:connection_type,bluetooth|nullable|string|max:255',
        ]);

        $printer->update($request->only([
            'name', 'connection_type', 'ip_address', 
            'port', 'bluetooth_identifier', 'is_active', 
            'paper_size'
        ]));

        return response()->json([
            'success' => true,
            'data' => $printer->load('store:id,name,nickname')
        ]);
    }

    /**
     * Test a printer connection
     */
    public function test(Request $request, string $printerId): JsonResponse
    {
        $user = $request->user();
        
        $printer = Printer::where('id', $printerId)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (!$printer) {
            return response()->json([
                'success' => false,
                'message' => 'Printer not found'
            ], 404);
        }

        // In a real implementation, this would test the actual printer connection
        // For now, we'll simulate the test
        $testResult = [
            'printer_id' => $printer->id,
            'connection_status' => 'connected', // This would be determined by actual connection test
            'message' => 'Printer connection test successful'
        ];

        return response()->json([
            'success' => true,
            'data' => $testResult
        ]);
    }

    /**
     * Get printer job status
     */
    public function getJobStatus(Request $request, string $printerId, string $jobId): JsonResponse
    {
        $user = $request->user();
        
        $printer = Printer::where('id', $printerId)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (!$printer) {
            return response()->json([
                'success' => false,
                'message' => 'Printer not found'
            ], 404);
        }

        $job = PrinterJob::where('id', $jobId)
            ->where('printer_id', $printerId)
            ->first();

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => 'Printer job not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $job
        ]);
    }
}