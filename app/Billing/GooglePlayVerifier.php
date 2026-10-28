<?php

namespace App\Billing;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Google Play Developer API: purchases.subscriptionsv2. */
class GooglePlayVerifier implements PurchaseVerifier
{
    private const STATES = [
        'SUBSCRIPTION_STATE_ACTIVE' => 'active',
        'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => 'grace_period',
        'SUBSCRIPTION_STATE_CANCELED' => 'canceled',
        'SUBSCRIPTION_STATE_ON_HOLD' => 'expired',
        'SUBSCRIPTION_STATE_PAUSED' => 'expired',
        'SUBSCRIPTION_STATE_PENDING' => 'pending',
        'SUBSCRIPTION_STATE_EXPIRED' => 'expired',
    ];

    public function verify(User $user, string $platform, string $productId, string $token): VerifiedPurchase
    {
        $package = config('sira.billing.google.package_name');
        $base = "https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$package}/purchases";
        $accessToken = $this->accessToken();

        $res = $this->send(fn () => Http::withToken($accessToken)->get("{$base}/subscriptionsv2/tokens/".rawurlencode($token)));
        if (in_array($res->status(), [400, 404, 410], true)) {
            throw BillingException::invalid();
        }
        $this->ensureOk($res);
        $body = $res->json();

        $line = collect($body['lineItems'] ?? [])->firstWhere('productId', $productId);
        if (! $line) {
            throw BillingException::invalid('This purchase is for a different product.');
        }

        $status = self::STATES[$body['subscriptionState'] ?? ''] ?? 'expired';

        // Unacknowledged purchases are refunded by Google after three days.
        if (($body['acknowledgementState'] ?? null) === 'ACKNOWLEDGEMENT_STATE_PENDING' && in_array($status, ['active', 'grace_period'], true)) {
            $this->ensureOk($this->send(fn () => Http::withToken($accessToken)
                ->post("{$base}/subscriptions/".rawurlencode($productId).'/tokens/'.rawurlencode($token).':acknowledge', (object) [])));
        }

        return new VerifiedPurchase(
            platform: 'google_play',
            productId: $productId,
            storeTransactionId: $token,
            status: $status,
            autoRenewing: (bool) ($line['autoRenewingPlan']['autoRenewEnabled'] ?? false),
            startsAt: isset($body['startTime']) ? Carbon::parse($body['startTime']) : null,
            expiresAt: isset($line['expiryTime']) ? Carbon::parse($line['expiryTime']) : null,
            raw: $body,
        );
    }

    private function accessToken(): string
    {
        $path = config('sira.billing.google.service_account_json');
        $account = $path && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($account) || empty($account['client_email']) || empty($account['private_key'])) {
            throw BillingException::notConfigured('Google Play');
        }

        return Cache::remember('google-play-token:'.md5($account['client_email']), now()->addMinutes(50), function () use ($account) {
            $tokenUri = $account['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $assertion = Jwt::sign([], [
                'iss' => $account['client_email'],
                'scope' => 'https://www.googleapis.com/auth/androidpublisher',
                'aud' => $tokenUri,
                'iat' => time(),
                'exp' => time() + 3600,
            ], $account['private_key'], 'RS256');

            $res = $this->send(fn () => Http::asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]));
            $this->ensureOk($res);

            return (string) $res->json('access_token');
        });
    }

    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw BillingException::storeUnavailable('Google Play');
        }
    }

    private function ensureOk(Response $res): void
    {
        if ($res->failed()) {
            report(new \RuntimeException("Google Play API returned {$res->status()}: ".substr($res->body(), 0, 500)));
            throw BillingException::storeUnavailable('Google Play');
        }
    }
}
