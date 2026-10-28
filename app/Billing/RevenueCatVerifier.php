<?php

namespace App\Billing;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * RevenueCat REST API v1. The app logs in to RevenueCat as `sira_<user id>`, so the token
 * must be the caller's own app user id; RevenueCat has already validated the receipt with the store.
 */
class RevenueCatVerifier implements PurchaseVerifier
{
    public function verify(User $user, string $platform, string $productId, string $token): VerifiedPurchase
    {
        $key = config('sira.billing.revenuecat.secret_api_key');
        if (! $key) {
            throw BillingException::notConfigured('RevenueCat');
        }
        if ($token !== "sira_{$user->id}") {
            throw BillingException::invalid('This purchase belongs to a different account.');
        }

        try {
            $res = Http::withToken($key)->acceptJson()->get('https://api.revenuecat.com/v1/subscribers/'.rawurlencode($token));
        } catch (ConnectionException) {
            throw BillingException::storeUnavailable('RevenueCat');
        }
        if ($res->failed()) {
            report(new \RuntimeException("RevenueCat returned {$res->status()}: ".substr($res->body(), 0, 500)));
            throw BillingException::storeUnavailable('RevenueCat');
        }

        $subscriber = $res->json('subscriber') ?? [];
        $entitlement = $subscriber['entitlements'][config('sira.billing.revenuecat.entitlement')] ?? null;
        // Restores may name the other plan; the entitlement says which product is really active.
        $productId = $entitlement['product_identifier'] ?? $productId;
        $sub = $subscriber['subscriptions'][$productId] ?? [];
        $expiresAt = isset($entitlement['expires_date']) ? Carbon::parse($entitlement['expires_date']) : null;

        $status = match (true) {
            ! $entitlement || ($expiresAt && $expiresAt->isPast()) => 'expired',
            ! empty($sub['refunded_at']) => 'revoked',
            ! empty($sub['billing_issues_detected_at']) => 'grace_period',
            ! empty($sub['unsubscribe_detected_at']) => 'canceled',
            default => 'active',
        };

        return new VerifiedPurchase(
            platform: 'revenuecat',
            productId: $productId,
            storeTransactionId: $token,
            status: $status,
            autoRenewing: $status === 'active',
            startsAt: isset($sub['original_purchase_date']) ? Carbon::parse($sub['original_purchase_date']) : null,
            expiresAt: $expiresAt,
            raw: ['entitlement' => $entitlement, 'subscription' => $sub],
        );
    }
}
