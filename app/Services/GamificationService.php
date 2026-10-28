<?php

namespace App\Services;

use App\Models\User;
use App\Models\XpEvent;
use App\Support\Achievements;
use App\Support\Levels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * XP is a ledger (xp_events) with users.xp as its running total. Each award is one row keyed
 * by (reason, source_type, source_id), so awarding twice is a no-op and undoing the action
 * (reopening a task, deleting a session) deletes exactly that row. Achievement XP is permanent.
 */
class GamificationService
{
    public function __construct(private StatsService $stats) {}

    /**
     * Sets the XP held for one source to $amount (0 removes it). Calling it again with the same
     * amount changes nothing, which keeps replays and double taps from paying out twice.
     *
     * @return list<string> achievement keys unlocked
     */
    public function award(User $user, int $amount, string $reason, string $sourceType, string $sourceId): array
    {
        DB::transaction(function () use ($user, $amount, $reason, $sourceType, $sourceId) {
            $event = $this->event($user, $reason, $sourceType, $sourceId);
            $diff = $amount - (int) $event?->amount;
            if ($diff === 0) {
                return;
            }

            if ($amount === 0) {
                $event->delete();
            } elseif ($event) {
                $event->update(['amount' => $amount]);
            } else {
                $user->xpEvents()->create(['amount' => $amount, 'reason' => $reason, 'source_type' => $sourceType, 'source_id' => $sourceId]);
            }
            $this->addXp($user, $diff);
        });

        return $this->checkAchievements($user);
    }

    public function revoke(User $user, string $reason, string $sourceType, string $sourceId): void
    {
        $this->award($user, 0, $reason, $sourceType, $sourceId);
    }

    public function awarded(User $user, string $reason, string $sourceType, string $sourceId): int
    {
        return (int) $this->event($user, $reason, $sourceType, $sourceId)?->amount;
    }

    /** @return list<string> */
    public function checkAchievements(User $user): array
    {
        $unlocked = [];
        $owned = $user->achievements()->pluck('achievement_key')->flip();

        // Achievement XP can lift the level, which can unlock a level achievement, so loop until stable.
        do {
            $metrics = $this->metrics($user);
            $new = [];
            foreach (Achievements::all() as $key => $def) {
                if (! isset($owned[$key]) && ($metrics[$def['metric']] ?? 0) >= $def['threshold']) {
                    $new[$key] = $def;
                }
            }
            foreach ($new as $key => $def) {
                $owned[$key] = true;
                $created = $user->achievements()->firstOrCreate(['achievement_key' => $key], ['unlocked_at' => now()]);
                if ($created->wasRecentlyCreated) {
                    DB::transaction(function () use ($user, $key, $def) {
                        $user->xpEvents()->create(['amount' => $def['xp'], 'reason' => 'achievement', 'source_type' => 'achievement', 'source_id' => $key]);
                        $this->addXp($user, $def['xp']);
                    });
                    $unlocked[] = $key;
                }
            }
        } while ($new !== []);

        return $unlocked;
    }

    /** @return array<string, int> */
    public function metrics(User $user): array
    {
        return [
            'tasks_completed' => $user->tasks()->completed()->count(),
            'day_streak' => $this->stats->dayStreak($user),
            'focus_minutes' => intdiv((int) $user->focusSessions()->where('type', 'focus')->sum('duration_seconds'), 60),
            'best_habit_streak' => $this->stats->bestHabitStreak($user),
            'goals_completed' => $user->goals()->where('status', 'completed')->count(),
            'level' => $user->level(),
        ];
    }

    public function profile(User $user): array
    {
        $xp = (int) $user->xp;
        $level = Levels::forXp($xp);

        return [
            'xp' => $xp,
            'level' => $level,
            'level_title' => Levels::title($level),
            'level_progress' => Levels::progress($xp),
            'day_streak' => $this->stats->dayStreak($user),
        ];
    }

    public function summary(User $user): array
    {
        $metrics = $this->metrics($user);
        $owned = $user->achievements()->pluck('unlocked_at', 'achievement_key');

        $achievements = collect(Achievements::all())->map(fn ($def, $key) => [
            'key' => $key,
            'title' => $def['title'],
            'description' => $def['description'],
            'icon' => $def['icon'],
            'xp' => $def['xp'],
            'progress' => min($metrics[$def['metric']] ?? 0, $def['threshold']),
            'threshold' => $def['threshold'],
            'unlocked_at' => isset($owned[$key]) ? Carbon::parse($owned[$key])->toIso8601String() : null,
        ])->values();

        $recent = $user->xpEvents()->latest('id')->limit(20)->get()
            ->map(fn (XpEvent $e) => ['amount' => $e->amount, 'reason' => $e->reason, 'created_at' => $e->created_at?->toIso8601String()]);

        return $this->profile($user) + ['metrics' => $metrics, 'achievements' => $achievements, 'recent_xp' => $recent];
    }

    private function event(User $user, string $reason, string $sourceType, string $sourceId): ?XpEvent
    {
        return $user->xpEvents()->where(['reason' => $reason, 'source_type' => $sourceType, 'source_id' => $sourceId])->first();
    }

    private function addXp(User $user, int $diff): void
    {
        $user->forceFill(['xp' => max(0, (int) $user->xp + $diff)])->save();
    }
}
