<?php

namespace App\Billing;

use Illuminate\Support\Carbon;

/** What a store (or RevenueCat) says about a purchase. */
final class VerifiedPurchase
{
    public function __construct(
        public readonly string $platform,
        public readonly string $productId,
        /** Stable id used to re-verify and to stop one purchase unlocking two accounts. */
        public readonly string $storeTransactionId,
        /** active | grace_period | canceled | pending | expired | revoked (on hold / paused / billing retry count as expired) */
        public readonly string $status,
        public readonly bool $autoRenewing,
        public readonly ?Carbon $startsAt,
        public readonly ?Carbon $expiresAt,
        public readonly array $raw = [],
    ) {}
}
