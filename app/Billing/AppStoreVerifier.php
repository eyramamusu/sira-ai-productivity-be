<?php

namespace App\Billing;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/** App Store Server API: Get All Subscription Statuses. */
class AppStoreVerifier implements PurchaseVerifier
{
    // https://developer.apple.com/documentation/appstoreserverapi/status
    private const STATUSES = [1 => 'active', 2 => 'expired', 3 => 'expired', 4 => 'grace_period', 5 => 'revoked'];

    public function verify(User $user, string $platform, string $productId, string $token): VerifiedPurchase
    {
        $config = config('sira.billing.apple');
        $host = ($config['environment'] ?? 'production') === 'sandbox' ? 'api.storekit-sandbox.itunes.apple.com' : 'api.storekit.itunes.apple.com';

        try {
            $res = Http::withToken($this->token($config))->get("https://{$host}/inApps/v1/subscriptions/".rawurlencode($token));
        } catch (ConnectionException) {
            throw BillingException::storeUnavailable('The App Store');
        }
        if (in_array($res->status(), [400, 404], true)) {
            throw BillingException::invalid();
        }
        if ($res->failed()) {
            report(new \RuntimeException("App Store API returned {$res->status()}: ".substr($res->body(), 0, 500)));
            throw BillingException::storeUnavailable('The App Store');
        }
        if (($res->json('bundleId') ?? $config['bundle_id']) !== $config['bundle_id']) {
            throw BillingException::invalid('This purchase belongs to a different app.');
        }

        // The signed payloads come straight from Apple over TLS, so their fields are read without re-checking the signature chain.
        foreach ($res->json('data') ?? [] as $group) {
            foreach ($group['lastTransactions'] ?? [] as $item) {
                $tx = Jwt::payload($item['signedTransactionInfo'] ?? '');
                if (($tx['productId'] ?? null) !== $productId) {
                    continue;
                }
                $renewal = Jwt::payload($item['signedRenewalInfo'] ?? '');

                return new VerifiedPurchase(
                    platform: 'app_store',
                    productId: $productId,
                    storeTransactionId: (string) ($item['originalTransactionId'] ?? $tx['originalTransactionId']),
                    status: self::STATUSES[$item['status'] ?? 0] ?? 'expired',
                    autoRenewing: (int) ($renewal['autoRenewStatus'] ?? 0) === 1,
                    startsAt: isset($tx['purchaseDate']) ? Carbon::createFromTimestampMs($tx['purchaseDate']) : null,
                    expiresAt: isset($tx['expiresDate']) ? Carbon::createFromTimestampMs($tx['expiresDate']) : null,
                    raw: ['status' => $item['status'] ?? null, 'transaction' => $tx],
                );
            }
        }

        throw BillingException::invalid('This purchase is for a different product.');
    }

    private function token(array $config): string
    {
        $path = $config['private_key_path'] ?? null;
        if (empty($config['issuer_id']) || empty($config['key_id']) || empty($config['bundle_id']) || ! $path || ! is_readable($path)) {
            throw BillingException::notConfigured('App Store');
        }

        return Jwt::sign(['kid' => $config['key_id']], [
            'iss' => $config['issuer_id'],
            'iat' => time(),
            'exp' => time() + 1200,
            'aud' => 'appstore-connect-v1',
            'bid' => $config['bundle_id'],
        ], (string) file_get_contents($path), 'ES256');
    }
}
