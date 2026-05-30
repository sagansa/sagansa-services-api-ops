<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QrisService;
use Illuminate\Http\Request;

use App\Models\PaymentMethod;
use App\Models\Store;
use Illuminate\Support\Facades\Validator;

class PaymentMethodController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|uuid|exists:stores,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $paymentMethods = PaymentMethod::where('store_id', $request->store_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $paymentMethods]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|uuid|exists:stores,id',
            'type' => 'required|string|in:cash,qris,transfer,debit,online',
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',
            'details' => 'nullable|array',
            'require_proof' => 'boolean',
            'qr_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Max 2MB
            'payment_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Generic image
            'details.qris_payload' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->except(['qr_image', 'payment_image']);
        
        // Handle QRIS image upload (Legacy support)
        if ($request->hasFile('qr_image') && $request->type === 'qris') {
            $file = $request->file('qr_image');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('qris', $filename, 'public');
            
            // Store the path in details
            $details = $data['details'] ?? [];
            if (is_string($details)) $details = json_decode($details, true);
            $details['qr_image'] = '/storage/' . $path;
            $details['qris_source'] = 'uploaded_image';
            $data['details'] = $details;
        }

        $data['details'] = $this->normaliseQrisDetails($data['details'] ?? []);

        // Handle Generic Payment Image upload
        if ($request->hasFile('payment_image')) {
            $file = $request->file('payment_image');
            $filename = time() . '_img_' . $file->getClientOriginalName();
            $path = $file->storeAs('payment-methods', $filename, 'public');
            
            // Store the path in details
            $details = $data['details'] ?? [];
            if (is_string($details)) $details = json_decode($details, true);
            $details['image'] = '/storage/' . $path;
            $data['details'] = $details;
        }

        $paymentMethod = PaymentMethod::create($data);

        return response()->json(['data' => $paymentMethod], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $paymentMethod = PaymentMethod::findOrFail($id);
        return response()->json(['data' => $paymentMethod]);
    }

    public function update(Request $request, string $id)
    {
        $paymentMethod = PaymentMethod::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'type' => 'sometimes|string|in:cash,qris,transfer,debit,online',
            'name' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
            'details' => 'nullable|array',
            'require_proof' => 'sometimes|boolean',
            'qr_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Max 2MB
            'payment_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Generic image
            'details.qris_payload' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->except(['qr_image', 'payment_image']);
        
        // Handle QRIS image upload
        if ($request->hasFile('qr_image')) {
            // Delete old image if exists
            if ($paymentMethod->details && isset($paymentMethod->details['qr_image'])) {
                $oldPath = str_replace('/storage/', '', $paymentMethod->details['qr_image']);
                \Storage::disk('public')->delete($oldPath);
            }
            
            $file = $request->file('qr_image');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('qris', $filename, 'public');
            
            // Store the path in details
            $details = $data['details'] ?? ($paymentMethod->details ?? []);
            if (is_string($details)) $details = json_decode($details, true);
            $details['qr_image'] = '/storage/' . $path;
            $details['qris_source'] = 'uploaded_image';
            $data['details'] = $details;
        }

        $data['details'] = $this->normaliseQrisDetails($data['details'] ?? ($paymentMethod->details ?? []));

        // Handle Generic Payment Image upload
        if ($request->hasFile('payment_image')) {
            // Delete old image if exists
            if ($paymentMethod->details && isset($paymentMethod->details['image'])) {
                $oldPath = str_replace('/storage/', '', $paymentMethod->details['image']);
                \Storage::disk('public')->delete($oldPath);
            }
            
            $file = $request->file('payment_image');
            $filename = time() . '_img_' . $file->getClientOriginalName();
            $path = $file->storeAs('payment-methods', $filename, 'public');
            
            // Store the path in details
            $details = $data['details'] ?? ($paymentMethod->details ?? []);
            if (is_string($details)) $details = json_decode($details, true);
            $details['image'] = '/storage/' . $path;
            $data['details'] = $details;
        }

        $paymentMethod->update($data);

        return response()->json(['data' => $paymentMethod]);
    }

    private function normaliseQrisDetails(array|string|null $details): array
    {
        if (is_string($details)) {
            $details = json_decode($details, true) ?: [];
        }

        if (! is_array($details)) {
            return [];
        }

        if (! empty($details['qris_payload'])) {
            unset($details['qr_image']);
            unset($details['qr_image_file']);
            $details['qris_source'] = 'payload';
            $details['qris_payload'] = trim((string) $details['qris_payload']);
        }

        return $details;
    }

    public function qris(Request $request, string $id, QrisService $qrisService)
    {
        $paymentMethod = PaymentMethod::findOrFail($id);

        if ($paymentMethod->type !== 'qris') {
            return response()->json([
                'message' => 'Payment method is not QRIS.',
            ], 422);
        }

        $details = $paymentMethod->details ?? [];
        $payload = $details['qris_payload'] ?? null;

        if (! $payload) {
            return response()->json([
                'message' => 'QRIS payload is not available. Upload a readable QRIS image first.',
            ], 422);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        $dynamicPayload = $qrisService->withAmount($payload, $validated['amount']);
        $svg = $qrisService->toSvg($dynamicPayload);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-QRIS-Payload' => $dynamicPayload,
        ]);
    }

    public function destroy(string $id)
    {
        $paymentMethod = PaymentMethod::findOrFail($id);
        
        // Prevent deletion of default payment methods
        if ($paymentMethod->is_default) {
            return response()->json([
                'success' => false,
                'message' => 'Metode pembayaran default (Tunai) tidak dapat dihapus.'
            ], 422);
        }
        
        $paymentMethod->delete();

        return response()->json(['message' => 'Payment method deleted successfully']);
    }
}
