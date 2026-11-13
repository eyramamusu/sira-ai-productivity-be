<?php

namespace App\Billing;

use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionUpdated;
use Illuminate\Database\UniqueConstraintViolationException;

class SubscriptionService
{
    public function __construct(private PurchaseVerifier $verifier) {}

    /** Verify a purchase with the store and link it to $user. */
    public function verify(User $user, string $platform, string $productId, string $token): Subscription
    {
        $plan = $this->planFor($productId);
        if (! $plan) {
            throw BillingException::unknownProduct();
        }

        $purchase = $this->verifier->verify($user, $platform, $productId, $token);
        $hash = Subscription::hashToken($purchase->platform, $purchase->storeTransactionId);

        $existing = Subscription::where('store_transaction_hash', $hash)->first();
        if ($existing && $existing->user_id !== $user->id) {
            throw BillingException::inUse();
        }

        $subscription = $existing ?? new Subscription(['store_transaction_hash' => $hash]);
        $before = $this->state($subscription);
        $subscription->user()->associate($user);
        $this->fill($subscription, $purchase);

        try {
            $subscription->save();
        } catch (UniqueConstraintViolationException) {
            throw BillingException::inUse(); // lost a race with another account
        }

        $this->announce($subscription, $before);

        return $subscription;
    }

    /** Re-check a stored subscription with the store (webhooks and the hourly refresh). */
    public function refresh(Subscription $subscription): Subscription
    {
        $purchase = $this->verifier->verify($subscription->user, $subscription->platform, $subscription->product_id, $subscription->store_transaction_id);
        $before = $this->state($subscription);
        $this->fill($subscription, $purchase);
        $subscription->save();
        $this->announce($subscription, $before);

        return $subscription;
    }

    /** Marks a lapsed subscription expired (the hourly safety net when the store couldn't be reached). */
    public function expire(Subscription $subscription): void
    {
        $before = $this->state($subscription);
        $subscription->update(['status' => 'expired']);
        $this->announce($subscription, $before);
    }

    public function findByToken(string $platform, string $token): ?Subscription
    {
        return Subscription::where('store_transaction_hash', Subscription::hashToken($platform, $token))->first();
    }

    public function entitlements(User $user): array
    {
        $active = $user->activeSubscription();
        $premium = $active !== null;
        $features = array_fill_keys(['advanced_analytics', 'productivity_reports', 'ai_coach', 'advanced_planning', 'unlimited_habits', 'unlimited_goals'], $premium);

        return [
            'plan' => $premium ? 'premium' : 'free',
            'is_premium' => $premium,
            'subscription' => $active?->toApi(),
            'limits' => [
                'max_active_habits' => $premium ? null : config('sira.free.max_active_habits'),
                'max_active_goals' => $premium ? null : config('sira.free.max_active_goals'),
                'analytics_max_days' => $premium ? config('sira.premium.analytics_max_days') : config('sira.free.analytics_max_days'),
            ],
            'features' => $features,
            'products' => collect(config('sira.products'))->map(fn ($id, $plan) => ['product_id' => $id, 'plan' => $plan])->values()->all(),
        ];
    }

    public function planFor(string $productId): ?string
    {
        return array_search($productId, config('sira.products'), true) ?: null;
    }

    /** @return array{entitled: bool, status: ?string, auto_renewing: bool} */
    private function state(Subscription $subscription): array
    {
        return [
            'entitled' => $subscription->exists && $this->isEntitled($subscription),
            'status' => $subscription->exists ? $subscription->status : null,
            'auto_renewing' => (bool) $subscription->auto_renewing,
        ];
    }

    private function isEntitled(Subscription $subscription): bool
    {
        return in_array($subscription->status, Subscription::ENTITLED_STATUSES, true) && $subscription->expires_at?->isFuture();
    }

    /** Tells the user when premium starts, has a payment problem, stops renewing, or ends. */
    private function announce(Subscription $subscription, array $before): void
    {
        $user = $subscription->user;
        $entitled = $this->isEntitled($subscription);
        $expiresOn = $subscription->expires_at?->copy()->setTimezone($user->timezone)->toFormattedDateString();

        $event = match (true) {
            $entitled && ! $before['entitled'] => SubscriptionUpdated::ACTIVATED,
            ! $entitled && $before['entitled'] => SubscriptionUpdated::EXPIRED,
            $subscription->status === 'grace_period' && $before['status'] !== 'grace_period' => SubscriptionUpdated::BILLING_ISSUE,
            $entitled && $before['auto_renewing'] && ! $subscription->auto_renewing => SubscriptionUpdated::RENEWAL_OFF,
            default => null,
        };

        if ($event) {
            $user->notify(new SubscriptionUpdated($event, $expiresOn));
        }
    }

    private function fill(Subscription $subscription, VerifiedPurchase $purchase): void
    {
        $subscription->fill([
            'platform' => $purchase->platform,
            'product_id' => $purchase->productId,
            'plan' => $this->planFor($purchase->productId) ?? 'monthly',
            'store_transaction_id' => $purchase->storeTransactionId,
            'status' => $purchase->status,
            'auto_renewing' => $purchase->autoRenewing,
            'starts_at' => $purchase->startsAt,
            'expires_at' => $purchase->expiresAt,
            'last_verified_at' => now(),
            'raw' => $purchase->raw,
        ]);
    }
}
