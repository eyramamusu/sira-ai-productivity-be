<?php

namespace App\Http\Controllers\Api;

use App\Billing\BillingException;
use App\Billing\Jwt;
use App\Billing\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->entitlements($request->user())]);
    }

    public function verify(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'platform' => ['required', Rule::in(['google_play', 'app_store', 'revenuecat'])],
            'product_id' => ['required', 'string', 'max:100'],
            'purchase_token' => ['required', 'string', 'max:4096'],
        ]);

        $this->subscriptions->verify($user, $data['platform'], $data['product_id'], $data['purchase_token']);

        return response()->json(['data' => $this->subscriptions->entitlements($user)]);
    }

    /** Play Real-time Developer Notifications via a Pub/Sub push subscription (`?token=` shared secret). */
    public function googleWebhook(Request $request): JsonResponse
    {
        $this->requireSecret(config('sira.billing.google.rtdn_token'), (string) $request->query('token'));

        $payload = json_decode(base64_decode((string) $request->input('message.data')), true) ?: [];
        $token = $payload['subscriptionNotification']['purchaseToken'] ?? null;
        if ($token && ($subscription = $this->subscriptions->findByToken('google_play', $token))) {
            $this->refreshQuietly(fn () => $this->subscriptions->refresh($subscription));
        }

        return response()->json(['ok' => true]);
    }

    /**
     * App Store Server Notifications V2. The payload isn't trusted: it only tells us which
     * subscription to re-verify with Apple's API.
     */
    public function appleWebhook(Request $request): JsonResponse
    {
        $notification = Jwt::payload((string) $request->input('signedPayload'));
        $transaction = Jwt::payload((string) ($notification['data']['signedTransactionInfo'] ?? ''));
        $originalId = $transaction['originalTransactionId'] ?? null;

        if ($originalId && ($subscription = $this->subscriptions->findByToken('app_store', (string) $originalId))) {
            $this->refreshQuietly(fn () => $this->subscriptions->refresh($subscription));
        }

        return response()->json(['ok' => true]);
    }

    /** RevenueCat webhooks (`Authorization: Bearer <REVENUECAT_WEBHOOK_AUTH>`), re-verified with the REST API. */
    public function revenueCatWebhook(Request $request): JsonResponse
    {
        $this->requireSecret(config('sira.billing.revenuecat.webhook_auth'), (string) $request->bearerToken());

        $appUserId = (string) $request->input('event.app_user_id');
        $productId = (string) $request->input('event.product_id');
        if (preg_match('/^sira_(\d+)$/', $appUserId, $m) && ($user = User::find($m[1]))) {
            $subscription = $this->subscriptions->findByToken('revenuecat', $appUserId);
            $this->refreshQuietly(fn () => $subscription
                ? $this->subscriptions->refresh($subscription)
                : $this->subscriptions->verify($user, 'revenuecat', $productId, $appUserId));
        }

        return response()->json(['ok' => true]);
    }

    private function requireSecret(?string $expected, string $given): void
    {
        abort_unless($expected && $given !== '' && hash_equals($expected, $given), 401, 'Invalid webhook credentials.');
    }

    /** Stores retry on non-2xx, so failures are logged and acknowledged; the hourly refresh catches up. */
    private function refreshQuietly(callable $refresh): void
    {
        try {
            $refresh();
        } catch (BillingException $e) {
            report($e);
        }
    }
}
