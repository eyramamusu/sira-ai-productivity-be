<?php

namespace App\Billing;

use App\Models\User;
use RuntimeException;

/**
 * Local development and tests only. Tokens starting with `test-` are accepted;
 * `test-expired` returns a lapsed subscription. Anything else is rejected.
 */
class FakeVerifier implements PurchaseVerifier
{
    public function __construct()
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The fake billing driver only runs in local or testing environments.');
        }
    }

    public function verify(User $user, string $platform, string $productId, string $token): VerifiedPurchase
    {
        if (! str_starts_with($token, 'test-')) {
            throw BillingException::invalid('Unknown test purchase token.');
        }

        $annual = $productId === config('sira.products.annual');
        $expired = $token === 'test-expired';

        return new VerifiedPurchase(
            platform: $platform,
            productId: $productId,
            storeTransactionId: $token,
            status: $expired ? 'expired' : 'active',
            autoRenewing: ! $expired,
            startsAt: now()->subMinute(),
            expiresAt: $expired ? now()->subDay() : ($annual ? now()->addYear() : now()->addMonth()),
            raw: ['fake' => true],
        );
    }
}
