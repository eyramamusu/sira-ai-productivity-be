<?php

use App\Billing\BillingException;
use App\Billing\SubscriptionService;
use App\Models\Subscription;
use App\Services\WeeklyReviewService;
use Illuminate\Notifications\DatabaseNotification;
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
    Subscription::whereIn('status', Subscription::ENTITLED_STATUSES)->where('expires_at', '<', now())->get()
        ->each(fn (Subscription $subscription) => $service->expire($subscription));

    $this->info("Checked {$due->count()} subscriptions.");
})->purpose('Re-verify store subscriptions that are about to renew or expire');

Schedule::command('sira:subscriptions:refresh')->hourly()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();

Artisan::command('sira:notifications:weekly-review', function (WeeklyReviewService $reviews) {
    $this->info('Sent '.$reviews->sendDue().' weekly reviews.');
})->purpose('Send the Monday 09:00 weekly review to users whose local time it is');

Artisan::command('sira:notifications:prune', function () {
    $deleted = DatabaseNotification::whereNotNull('read_at')
        ->where('created_at', '<', now()->subDays(config('sira.push.keep_read_days')))
        ->delete();
    $this->info("Deleted {$deleted} old notifications.");
})->purpose('Delete read inbox notifications older than the retention period');

Schedule::command('sira:notifications:weekly-review')->hourly()->withoutOverlapping();
Schedule::command('sira:notifications:prune')->daily();
// Notifications are queued. With only cron available, this drains the queue every minute;
// with a process manager, run `php artisan queue:work` instead and remove this line.
// With QUEUE_CONNECTION=sync (hosts without workers, e.g. Render's free tier) there is nothing to drain.
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')->everyMinute()->withoutOverlapping()
    ->when(fn () => config('queue.default') !== 'sync');
