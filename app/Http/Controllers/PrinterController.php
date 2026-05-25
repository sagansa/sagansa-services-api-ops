<?php

namespace App\Http\Controllers;

use App\Models\Printer;
use App\Models\Store;
use Illuminate\Http\Request;

class PrinterController extends ApiController
{
    /**
     * Display a listing of printers.
     */
    public function index(Request $request)
    {
        $tenantId = $this->currentTenantId();
        
        $query = Printer::where('tenant_id', $tenantId)
            ->with('store', 'printerJobs')
            ->orderBy('created_at', 'desc');
        
        // Add optional filters
        if ($request->has('store_id')) {
            $query->where('store_id', $request->store_id);
        }
        
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }
        
        if ($request->has('connection_type')) {
            $query->where('connection_type', $request->connection_type);
        }
        
        $printers = $query->paginate(10);

        return response()->json($printers);
    }

    /**
     * Store a newly created printer.
     */
    public function store(Request $request)
    {
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

        $tenantId = $this->currentTenantId();
        
        // Verify that the store belongs to the current tenant
        $store = Store::where('id', $request->store_id)
            ->where('tenant_id', $tenantId)
            ->first();
        
        if (!$store) {
            return response()->json(['error' => 'Unauthorized or store not found'], 403);
        }

        $printer = Printer::create(array_merge(
            $request->only([
                'store_id', 'name', 'connection_type', 
                'ip_address', 'port', 'bluetooth_identifier', 
                'is_active', 'paper_size'
            ]),
            ['tenant_id' => $tenantId]
        ));

        return response()->json($printer->load('store'), 201);
    }

    /**
     * Display the specified printer.
     */
    public function show(Printer $printer)
    {
        $tenantId = $this->currentTenantId();
        
        if ($printer->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json($printer->load(['store', 'printerJobs']));
    }

    /**
     * Update the specified printer.
     */
    public function update(Request $request, Printer $printer)
    {
        $tenantId = $this->currentTenantId();
        
        if ($printer->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
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

        return response()->json($printer->load('store'));
    }

    /**
     * Remove the specified printer from storage.
     */
    public function destroy(Printer $printer)
    {
        $tenantId = $this->currentTenantId();
        
        if ($printer->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $printer->delete();
        return response()->json(['message' => 'Printer deleted successfully']);
    }

    /**
     * Test the printer connection.
     */
    public function test(Printer $printer, Request $request)
    {
        $tenantId = $this->currentTenantId();
        
        if ($printer->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // In a real implementation, this would test the actual printer connection
        // For now, we'll simulate the test
        $testResult = [
            'printer_id' => $printer->id,
            'connection_status' => 'connected', // This would be determined by actual connection test
            'message' => 'Printer connection test successful'
        ];

        return response()->json($testResult);
    }
}