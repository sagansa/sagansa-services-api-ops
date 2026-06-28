<?php

namespace App\Services\Billing;

use App\Models\BillingSettings;
use InvalidArgumentException;

/**
 * Manager yang me-resolve provider aktif dari billing_settings.
 *
 * Saat generate invoice, panggil `app(PaymentProviderManager::class)->resolve()`.
 * Switch provider = ubah billing_settings.active_provider saja.
 */
class PaymentProviderManager
{
    public function resolve(): PaymentProviderInterface
    {
        $provider = BillingSettings::singleton()->active_provider;

        return match ($provider) {
            'xendit' => app(XenditProvider::class),
            'midtrans' => app(MidtransProvider::class),
            default => throw new InvalidArgumentException("Unknown payment provider: {$provider}"),
        };
    }

    public function resolveByName(string $name): PaymentProviderInterface
    {
        return match ($name) {
            'xendit' => app(XenditProvider::class),
            'midtrans' => app(MidtransProvider::class),
            default => throw new InvalidArgumentException("Unknown payment provider: {$name}"),
        };
    }
}
