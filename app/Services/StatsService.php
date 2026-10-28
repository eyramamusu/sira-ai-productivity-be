<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Read models for overview, calendar and analytics. Timestamps are stored in UTC and
 * bucketed into days in the user's timezone in PHP, which keeps the queries portable
 * between MySQL and SQLite.
 */
class StatsService
{
    /** Consecutive days, ending today or yesterday, with at least one completed task. */
    public function dayStreak(User $user): int
    {
        $tz = $user->timezone ?: 'UTC';
        $days = $user->tasks()->completed()
            ->where('completed_at', '>=', now()->subDays(400))
            ->pluck('completed_at')
            ->mapWithKeys(fn ($at) => [Carbon::parse($at)->setTimezone($tz)->toDateString() => true]);

        $day = Carbon::parse($user->today());
        if (! isset($days[$day->toDateString()])) {
            $day->subDay();
        }
        $streak = 0;
        while (isset($days[$day->toDateString()])) {
            $streak++;
            $day->subDay();
        }

        return $streak;
    }

    public function bestHabitStreak(User $user): int
    {
        $today = $user->today();

        return (int) $this->habitsWithHistory($user)->map(fn (Habit $h) => $h->currentStreak($today))->max();
    }

    /** Active habits with a year of logs, enough for streaks. */
    public function habitsWithHistory(User $user, bool $activeOnly = true): Collection
    {
        $since = Carbon::parse($user->today())->subDays(400)->toDateString();

        return $user->habits()
            ->when($activeOnly, fn ($q) => $q->active())
            ->with(['logs' => fn ($q) => $q->where('date', '>=', $since)])
            ->orderBy('created_at')
            ->get();
    }

    public function overview(User $user, string $date, GamificationService $gamification): array
    {
        $tasks = $user->tasks()->with(['category', 'subtasks'])
            ->where(fn ($q) => $q->where('due_date', $date)->orWhere(fn ($q) => $q->open()->where('due_date', '<', $date)))
            ->orderBy('due_date')->orderByRaw('start_time is null')->orderBy('start_time')->orderBy('position')
            ->get();

        $dueToday = $tasks->filter(fn (Task $t) => $t->due_date?->toDateString() === $date);
        $completed = $dueToday->filter->isCompleted()->count();
        $total = $dueToday->count();

        $day = Carbon::parse($date);
        $habits = $this->habitsWithHistory($user)->filter(fn (Habit $h) => $h->isScheduledOn($day));
        $habitsDone = $habits->filter(fn (Habit $h) => isset($h->doneDates()[$date]))->count();

        [$start, $end] = $this->utcBounds($user, $date, $date);
        $focusSeconds = (int) $user->focusSessions()->where('type', 'focus')->whereBetween('started_at', [$start, $end])->sum('duration_seconds');

        return [
            'date' => $date,
            'tasks' => $tasks,
            'progress' => ['completed' => $completed, 'total' => $total, 'percent' => $total ? (int) round($completed / $total * 100) : 0],
            'habits' => ['total' => $habits->count(), 'completed' => $habitsDone],
            'focus' => ['seconds' => $focusSeconds],
            'gamification' => $gamification->profile($user),
        ];
    }

    public function calendar(User $user, string $from, string $to): array
    {
        $tasks = $user->tasks()->with(['category', 'subtasks'])
            ->whereBetween('due_date', [$from, $to])
            ->orderBy('due_date')->orderByRaw('start_time is null')->orderBy('start_time')->orderBy('position')
            ->get();

        $habits = $user->habits()->active()->with(['logs' => fn ($q) => $q->whereBetween('date', [$from, $to])])->get();
        $days = [];
        foreach ($this->dateRange($from, $to) as $date) {
            $day = Carbon::parse($date);
            $scheduled = $habits->filter(fn (Habit $h) => $h->isScheduledOn($day));
            $days[] = [
                'date' => $date,
                'total' => $scheduled->count(),
                'completed' => $scheduled->filter(fn (Habit $h) => isset($h->doneDates()[$date]))->count(),
            ];
        }

        return ['from' => $from, 'to' => $to, 'tasks' => $tasks, 'habits' => $days];
    }

    public function analytics(User $user, string $from, string $to): array
    {
        $completed = $this->completedTasks($user, $from, $to);
        $sessions = $this->focusSessions($user, $from, $to);
        $tz = $user->timezone ?: 'UTC';

        $byDay = $completed->groupBy(fn (Task $t) => $t->completed_at->copy()->setTimezone($tz)->toDateString());
        $focusByDay = $sessions->groupBy(fn ($s) => $s->started_at->copy()->setTimezone($tz)->toDateString());

        $days = array_map(fn ($date) => [
            'date' => $date,
            'tasks_completed' => isset($byDay[$date]) ? $byDay[$date]->count() : 0,
            'focus_minutes' => isset($focusByDay[$date]) ? intdiv((int) $focusByDay[$date]->sum('duration_seconds'), 60) : 0,
        ], $this->dateRange($from, $to));

        $total = $completed->count();
        $categories = $completed->groupBy(fn (Task $t) => $t->category_id ?? '')
            ->map(fn (Collection $group, $id) => [
                'category_id' => $id === '' ? null : $id,
                'name' => $group->first()->category?->name ?? 'Uncategorized',
                'color' => $group->first()->category?->color ?? '#9E9E9E',
                'count' => $group->count(),
                'percent' => (int) round($group->count() / $total * 100),
            ])
            ->sortByDesc('count')->values();

        return [
            'from' => $from,
            'to' => $to,
            'totals' => [
                'tasks_completed' => $total,
                'focus_seconds' => (int) $sessions->sum('duration_seconds'),
                'focus_sessions' => $sessions->count(),
                'day_streak' => $this->dayStreak($user),
            ],
            'days' => $days,
            'categories' => $categories,
        ];
    }

    public function advanced(User $user, string $from, string $to): array
    {
        $tz = $user->timezone ?: 'UTC';
        $completed = $this->completedTasks($user, $from, $to);
        $sessions = $this->focusSessions($user, $from, $to);

        $length = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $prevTo = Carbon::parse($from)->subDay()->toDateString();
        $prevFrom = Carbon::parse($from)->subDays($length)->toDateString();
        $prevCompleted = $this->completedTasks($user, $prevFrom, $prevTo)->count();
        $prevFocus = (int) $this->focusSessions($user, $prevFrom, $prevTo)->sum('duration_seconds');

        $due = $user->tasks()->whereBetween('due_date', [$from, $to])->get();
        $dueDone = $due->filter->isCompleted();
        $onTime = $dueDone->filter(fn (Task $t) => $t->completed_at->copy()->setTimezone($tz)->toDateString() <= $t->due_date->toDateString());

        $byHour = array_fill(0, 24, 0);
        $byWeekday = array_fill(0, 7, 0);
        foreach ($completed as $task) {
            $local = $task->completed_at->copy()->setTimezone($tz);
            $byHour[$local->hour]++;
            $byWeekday[$local->dayOfWeekIso - 1]++;
        }
        $peak = max($byHour);

        $priorityMix = ['low' => 0, 'medium' => 0, 'high' => 0];
        foreach ($completed as $task) {
            $priorityMix[$task->priority] = ($priorityMix[$task->priority] ?? 0) + 1;
        }

        $focusSeconds = (int) $sessions->sum('duration_seconds');
        $today = $user->today();
        $habits = $this->habitsWithHistory($user)->map(function (Habit $habit) use ($from, $to, $today) {
            $done = $habit->doneDates();
            $scheduled = 0;
            $completedDays = 0;
            foreach ($this->dateRange(max($from, $habit->created_at->toDateString()), min($to, $today)) as $date) {
                if ($habit->isScheduledOn(Carbon::parse($date))) {
                    $scheduled++;
                    $completedDays += isset($done[$date]) ? 1 : 0;
                }
            }

            return [
                'id' => $habit->id,
                'name' => $habit->name,
                'color' => $habit->color,
                'consistency' => $scheduled ? (int) round($completedDays / $scheduled * 100) : 0,
                'current_streak' => $habit->currentStreak($today),
                'scheduled_days' => $scheduled,
                'completed_days' => $completedDays,
            ];
        })->values();

        return [
            'from' => $from,
            'to' => $to,
            'completion_rate' => $due->count() ? (int) round($dueDone->count() / $due->count() * 100) : 0,
            'on_time_rate' => $dueDone->count() ? (int) round($onTime->count() / $dueDone->count() * 100) : 0,
            'tasks_completed' => $completed->count(),
            'tasks_completed_change' => $this->change($completed->count(), $prevCompleted),
            'focus_seconds' => $focusSeconds,
            'focus_change' => $this->change($focusSeconds, $prevFocus),
            'avg_focus_session_minutes' => $sessions->count() ? (int) round($focusSeconds / $sessions->count() / 60) : 0,
            'by_hour' => $byHour,
            'by_weekday' => $byWeekday,
            'most_productive_hour' => $peak > 0 ? array_search($peak, $byHour, true) : null,
            'priority_mix' => $priorityMix,
            'habits' => $habits,
        ];
    }

    /** @return list<string> */
    public function highlights(array $summary, array $advanced): array
    {
        $out = [];
        $out[] = sprintf('You completed %d %s.', $summary['totals']['tasks_completed'], Str::plural('task', $summary['totals']['tasks_completed']));
        if ($summary['totals']['focus_seconds'] > 0) {
            $out[] = sprintf('You focused for %s across %d %s.', $this->duration($summary['totals']['focus_seconds']), $summary['totals']['focus_sessions'], Str::plural('session', $summary['totals']['focus_sessions']));
        }
        if ($advanced['tasks_completed_change'] !== null) {
            $out[] = sprintf('Completed tasks are %s %d%% on the previous period.', $advanced['tasks_completed_change'] >= 0 ? 'up' : 'down', abs($advanced['tasks_completed_change']));
        }
        if ($advanced['most_productive_hour'] !== null) {
            $out[] = sprintf('Your most productive hour is %s.', Carbon::createFromTime($advanced['most_productive_hour'])->format('g A'));
        }
        if ($top = $summary['categories'][0] ?? null) {
            $out[] = sprintf('Most of your work (%d%%) went into %s.', $top['percent'], $top['name']);
        }
        if ($best = collect($advanced['habits'])->sortByDesc('consistency')->first()) {
            $out[] = sprintf('Your most consistent habit is %s at %d%%.', $best['name'], $best['consistency']);
        }
        if ($summary['totals']['day_streak'] > 1) {
            $out[] = sprintf('You are on a %d-day streak.', $summary['totals']['day_streak']);
        }

        return $out;
    }

    /** @return list<string> every date from $from to $to inclusive */
    public function dateRange(string $from, string $to): array
    {
        $out = [];
        for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    /** @return array{0: Carbon, 1: Carbon} UTC instants covering the user's local days */
    public function utcBounds(User $user, string $from, string $to): array
    {
        $tz = $user->timezone ?: 'UTC';

        return [Carbon::parse($from, $tz)->startOfDay()->utc(), Carbon::parse($to, $tz)->endOfDay()->utc()];
    }

    private function completedTasks(User $user, string $from, string $to): Collection
    {
        return $user->tasks()->with('category')->completed()->whereBetween('completed_at', $this->utcBounds($user, $from, $to))->get();
    }

    private function focusSessions(User $user, string $from, string $to): Collection
    {
        return $user->focusSessions()->where('type', 'focus')->whereBetween('started_at', $this->utcBounds($user, $from, $to))->get();
    }

    private function change(int $now, int $before): ?int
    {
        return $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
    }

    private function duration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $h ? "{$h}h {$m}m" : "{$m}m";
    }
}
