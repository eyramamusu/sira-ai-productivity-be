<?php

namespace App\Billing;

use App\Models\User;

interface PurchaseVerifier
{
    /**
     * @param  string  $token  Play purchase token, App Store transaction id, or the RevenueCat app user id
     *
     * @throws BillingException
     */
    public function verify(User $user, string $platform, string $productId, string $token): VerifiedPurchase;
}
