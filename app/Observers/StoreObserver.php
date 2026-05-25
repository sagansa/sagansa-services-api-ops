<?php

namespace App\Observers;

use App\Models\Store;

class StoreObserver
{
    /**
     * Handle the Store "created" event.
     */
    public function created(Store $store): void
    {
        // Automatically create default cash payment method for new stores
        \App\Models\PaymentMethod::create([
            'store_id' => $store->id,
            'type' => 'cash',
            'name' => 'Tunai',
            'is_active' => true,
            'is_default' => true,
            'require_proof' => false,
            'details' => null,
        ]);
    }
}
