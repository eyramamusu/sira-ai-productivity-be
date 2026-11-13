<?php

namespace App\Services;

use App\Models\HabitLog;
use App\Models\User;
use App\Notifications\WeeklyReview;
use Illuminate\Support\Carbon;

/** Sends each user a recap of last week at 09:00 on Monday in their own time zone. */
class WeeklyReviewService
{
    public function __construct(private StatsService $stats) {}

    /** @return int how many reviews were sent */
    public function sendDue(?Carbon $now = null): int
    {
        $now ??= now();
        $hour = config('sira.push.weekly_review_hour');

        $zones = User::query()->distinct()->pluck('timezone')
            ->filter(fn (string $tz) => rescue(fn () => $now->copy()->setTimezone($tz), null, false)?->isMonday()
                && $now->copy()->setTimezone($tz)->hour === $hour)
            ->values();
        if ($zones->isEmpty()) {
            return 0;
        }

        $sent = 0;
        User::query()
            ->whereIn('timezone', $zones)
            ->whereDoesntHave('settings', fn ($q) => $q->where(fn ($q) => $q->where('weekly_review', false)->orWhere('notifications_enabled', false)))
            // One per week even if the job runs twice in the hour.
            ->whereDoesntHave('notifications', fn ($q) => $q->where('type', 'weekly_review')->where('created_at', '>', $now->copy()->subDays(6)))
            ->chunkById(200, function ($users) use ($now, &$sent) {
                foreach ($users as $user) {
                    $user->notify($this->reviewFor($user, $now));
                    $sent++;
                }
            });

        return $sent;
    }

    public function reviewFor(User $user, Carbon $now): WeeklyReview
    {
        $monday = $now->copy()->setTimezone($user->timezone)->startOfWeek(Carbon::MONDAY);
        $from = $monday->copy()->subWeek()->toDateString();
        $to = $monday->copy()->subDay()->toDateString();

        $totals = $this->stats->analytics($user, $from, $to)['totals'];
        $checkIns = HabitLog::query()
            ->whereIn('habit_id', $user->habits()->withTrashed()->select('id'))
            ->whereBetween('date', [$from, $to])
            ->count();

        return new WeeklyReview($totals['tasks_completed'], intdiv($totals['focus_seconds'], 60), $checkIns);
    }
}
