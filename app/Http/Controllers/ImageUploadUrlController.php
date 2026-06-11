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

        // Berlaku untuk 5 menit ke depan (300 detik)
        $expires = time() + 300;
        
        // Buat signature kriptografi
        $signature = hash_hmac('sha256', "expires={$expires}", $secret);

        // Bentuk full URL menuju img service (Bisa ditaruh di .env nantinya)
        $imgServiceUrl = 'https://img.sagansa.id/api/upload';

        $uploadUrl = "{$imgServiceUrl}?expires={$expires}&signature={$signature}";

        return response()->json([
            'success' => true,
            'upload_url' => $uploadUrl,
            'expires_at' => date('Y-m-d H:i:s', $expires)
        ]);
    }
}
