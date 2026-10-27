<?php

use App\Billing\BillingException;
use App\Billing\SubscriptionService;
use App\Models\Subscription;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 * Webhooks keep subscriptions current; this is the safety net for missed notifications.
 * It re-verifies anything that expires within a day or was last checked over a day ago.
 */
Artisan::command('sira:subscriptions:refresh', function (SubscriptionService $service) {
    $due = Subscription::whereIn('status', Subscription::ENTITLED_STATUSES)
        ->where('platform', '!=', 'manual')
        ->where(fn ($q) => $q->where('expires_at', '<', now()->addDay())->orWhere('last_verified_at', '<', now()->subDay()))
        ->get();

    foreach ($due as $subscription) {
        try {
            $service->refresh($subscription);
        } catch (BillingException $e) {
            $this->warn("Subscription {$subscription->id}: {$e->getMessage()}");
        }
    }

    // Anything past its end date stops granting premium even if the store could not be reached.
    Subscription::whereIn('status', Subscription::ENTITLED_STATUSES)->where('expires_at', '<', now())->update(['status' => 'expired']);

    $this->info("Checked {$due->count()} subscriptions.");
})->purpose('Re-verify store subscriptions that are about to renew or expire');

Schedule::command('sira:subscriptions:refresh')->hourly()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
