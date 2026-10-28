<?php

namespace App\Billing;

use App\Models\User;

/** Verifies directly with the store the purchase came from. */
class StoreVerifier implements PurchaseVerifier
{
    public function __construct(private GooglePlayVerifier $google, private AppStoreVerifier $apple, private RevenueCatVerifier $revenueCat) {}

    public function verify(User $user, string $platform, string $productId, string $token): VerifiedPurchase
    {
        return match ($platform) {
            'google_play' => $this->google->verify($user, $platform, $productId, $token),
            'app_store' => $this->apple->verify($user, $platform, $productId, $token),
            'revenuecat' => $this->revenueCat->verify($user, $platform, $productId, $token),
        };
    }
}
