<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * BillingSettings — konfigurasi global payment provider (singleton, 1 baris).
 *
 * Super-admin pilih Xendit/Midtrans + simpan API key via UI.
 */
class BillingSettings extends Model
{
    use HasFactory;

    protected $connection = 'mysql_ops';

    protected $table = 'billing_settings';

    protected $fillable = [
        'active_provider',
        'xendit_secret_key',
        'xendit_verify_key',
        'midtrans_server_key',
        'midtrans_client_key',
        'midtrans_is_production',
        'webhook_secret',
        'updated_by',
    ];

    protected $hidden = [
        'xendit_secret_key',
        'xendit_verify_key',
        'midtrans_server_key',
        'midtrans_client_key',
        'webhook_secret',
    ];

    protected $casts = [
        'midtrans_is_production' => 'boolean',
    ];

    /**
     * Ambil instance singleton (buat jika belum ada).
     */
    public static function singleton(): self
    {
        return static::first() ?? static::create([
            'active_provider' => 'xendit',
        ]);
    }
}
