<?php

namespace Tests\Feature;

use App\Billing\AppStoreVerifier;
use App\Billing\GooglePlayVerifier;
use App\Billing\Jwt;
use App\Billing\PurchaseVerifier;
use App\Billing\StoreVerifier;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_user_entitlements(): void
    {
        $this->signIn();
        $this->getJson('/api/v1/subscription')->assertOk()
            ->assertJsonPath('data.plan', 'free')
            ->assertJsonPath('data.features.ai_coach', false)
            ->assertJsonPath('data.limits.max_active_habits', 3);
    }

    public function test_verified_purchase_grants_premium(): void
    {
        $this->signIn();
        $this->postJson('/api/v1/subscription/verify', [
            'platform' => 'google_play', 'product_id' => 'sira_premium_annual', 'purchase_token' => 'test-abc',
        ])->assertOk()->assertJsonPath('data.plan', 'premium')->assertJsonPath('data.subscription.plan', 'annual');

        $this->getJson('/api/v1/me')->assertJsonPath('data.is_premium', true);
        $this->assertArrayNotHasKey('store_transaction_id', Subscription::first()->toArray());
    }

    public function test_expired_or_unknown_purchases_do_not_grant_premium(): void
    {
        $this->signIn();
        $this->postJson('/api/v1/subscription/verify', ['platform' => 'app_store', 'product_id' => 'sira_premium_monthly', 'purchase_token' => 'test-expired'])
            ->assertOk()->assertJsonPath('data.plan', 'free');
        $this->postJson('/api/v1/subscription/verify', ['platform' => 'app_store', 'product_id' => 'other_product', 'purchase_token' => 'test-x'])
            ->assertUnprocessable()->assertJsonPath('code', 'billing_unknown_product');
        $this->postJson('/api/v1/subscription/verify', ['platform' => 'app_store', 'product_id' => 'sira_premium_monthly', 'purchase_token' => 'forged'])
            ->assertUnprocessable();
    }

    public function test_a_purchase_cannot_unlock_two_accounts(): void
    {
        $this->signIn();
        $payload = ['platform' => 'google_play', 'product_id' => 'sira_premium_monthly', 'purchase_token' => 'test-shared'];
        $this->postJson('/api/v1/subscription/verify', $payload)->assertOk();

        $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/subscription/verify', $payload)->assertStatus(409)->assertJsonPath('code', 'billing_purchase_in_use');
    }

    public function test_google_play_verifier_reads_subscriptions_v2(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $this->opensslConfig());
        openssl_pkey_export($key, $pem, null, $this->opensslConfig());
        $path = tempnam(sys_get_temp_dir(), 'sa');
        file_put_contents($path, json_encode(['client_email' => 'svc@test.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']));
        config(['sira.billing.google.service_account_json' => $path]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'gtoken']),
            'androidpublisher.googleapis.com/*/subscriptionsv2/*' => Http::response([
                'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
                'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_PENDING',
                'startTime' => '2026-05-01T00:00:00Z',
                'lineItems' => [['productId' => 'sira_premium_monthly', 'expiryTime' => now()->addMonth()->toIso8601String(), 'autoRenewingPlan' => ['autoRenewEnabled' => true]]],
            ]),
            'androidpublisher.googleapis.com/*:acknowledge' => Http::response([]),
        ]);

        $purchase = app(GooglePlayVerifier::class)->verify(User::factory()->create(), 'google_play', 'sira_premium_monthly', 'play-token');

        $this->assertSame('active', $purchase->status);
        $this->assertTrue($purchase->autoRenewing);
        Http::assertSent(fn ($r) => str_contains($r->url(), ':acknowledge'));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer gtoken'));
        unlink($path);
    }

    public function test_app_store_verifier_reads_subscription_status(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'] + $this->opensslConfig());
        openssl_pkey_export($key, $pem, null, $this->opensslConfig());
        $path = tempnam(sys_get_temp_dir(), 'p8');
        file_put_contents($path, $pem);
        config(['sira.billing.apple' => [
            'bundle_id' => 'com.sira.productivity', 'issuer_id' => 'issuer', 'key_id' => 'KEY123',
            'private_key_path' => $path, 'environment' => 'sandbox',
        ]]);

        $jws = fn (array $p) => 'e30.'.Jwt::b64(json_encode($p)).'.sig';
        Http::fake([
            'api.storekit-sandbox.itunes.apple.com/*' => Http::response([
                'bundleId' => 'com.sira.productivity',
                'data' => [['lastTransactions' => [[
                    'status' => 1,
                    'originalTransactionId' => '2000001',
                    'signedTransactionInfo' => $jws(['productId' => 'sira_premium_annual', 'originalTransactionId' => '2000001', 'purchaseDate' => now()->getTimestampMs(), 'expiresDate' => now()->addYear()->getTimestampMs()]),
                    'signedRenewalInfo' => $jws(['autoRenewStatus' => 1]),
                ]]]],
            ]),
        ]);

        $purchase = app(AppStoreVerifier::class)->verify(User::factory()->create(), 'app_store', 'sira_premium_annual', '2000005');

        $this->assertSame('active', $purchase->status);
        $this->assertSame('2000001', $purchase->storeTransactionId);
        Http::assertSent(function ($request) use ($key) {
            [$h, $p, $s] = explode('.', substr($request->header('Authorization')[0], 7));
            $raw = Jwt::unb64($s);
            $this->assertSame(64, strlen($raw));
            $this->assertSame('appstore-connect-v1', json_decode(Jwt::unb64($p), true)['aud']);

            return true;
        });
        unlink($path);
    }

    public function test_store_driver_reports_missing_configuration(): void
    {
        $this->app->bind(PurchaseVerifier::class, StoreVerifier::class);
        $this->signIn();

        $this->postJson('/api/v1/subscription/verify', ['platform' => 'google_play', 'product_id' => 'sira_premium_monthly', 'purchase_token' => 'x'])
            ->assertStatus(503)->assertJsonPath('code', 'billing_not_configured');
    }

    public function test_webhooks_require_shared_secrets(): void
    {
        $this->postJson('/api/v1/webhooks/google-play?token=wrong', [])->assertUnauthorized();
        $this->postJson('/api/v1/webhooks/revenuecat', [])->assertUnauthorized();
    }

    public function test_google_webhook_refreshes_known_subscription(): void
    {
        config(['sira.billing.google.rtdn_token' => 'secret']);
        $user = $this->signIn();
        $this->postJson('/api/v1/subscription/verify', ['platform' => 'google_play', 'product_id' => 'sira_premium_monthly', 'purchase_token' => 'test-hook'])->assertOk();
        Subscription::first()->update(['status' => 'expired']);

        $data = base64_encode(json_encode(['subscriptionNotification' => ['purchaseToken' => 'test-hook', 'notificationType' => 2]]));
        $this->postJson('/api/v1/webhooks/google-play?token=secret', ['message' => ['data' => $data]])->assertOk();

        $this->assertSame('active', Subscription::first()->status);
        $this->assertTrue($user->fresh()->isPremium());
    }
}
