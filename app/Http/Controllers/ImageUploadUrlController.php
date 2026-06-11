<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ImageUploadUrlController extends Controller
{
    public function getUploadUrl(Request $request)
    {
        $secret = env('IMAGE_UPLOAD_SECRET');
        if (!$secret) {
            return response()->json(['error' => 'Secret not configured.'], 500);
        }

        // Optional directory for organized storage (e.g. "ops/product", "ops/attendance")
        $directory = trim($request->input('directory', ''), '/');

        // Berlaku untuk 5 menit ke depan (300 detik)
        $expires = time() + 300;
        
        // Buat signature kriptografi
        $signature = hash_hmac('sha256', "expires={$expires}", $secret);

        // Bentuk full URL menuju img service
        $imgServiceUrl = rtrim(env('IMG_SERVICE_URL', 'https://img.sagansa.id'), '/') . '/api/upload';

        $uploadUrl = "{$imgServiceUrl}?expires={$expires}&signature={$signature}";

        // Append directory if provided
        if ($directory) {
            $uploadUrl .= "&directory=" . urlencode($directory);
        }

        return response()->json([
            'success' => true,
            'upload_url' => $uploadUrl,
            'expires_at' => date('Y-m-d H:i:s', $expires)
        ]);
    }
}
