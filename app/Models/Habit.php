<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Habit extends Model
{
    use HasUuids, SoftDeletes;

    public const XP = 5;

    protected $fillable = ['id', 'name', 'icon', 'color', 'frequency', 'days_of_week', 'target_per_day', 'reminder_time', 'archived_at'];

    protected $attributes = ['icon' => 'check_circle', 'color' => '#8B5A3C', 'frequency' => 'daily', 'target_per_day' => 1];

    protected function casts(): array
    {
        return ['days_of_week' => 'array', 'target_per_day' => 'integer', 'archived_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(HabitLog::class)->orderBy('date');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** ISO weekday (1 = Monday … 7 = Sunday). Daily habits without days set run every day. */
    public function isScheduledOn(Carbon $date): bool
    {
        $days = $this->days_of_week ?: [];

        return $days === [] || in_array($date->dayOfWeekIso, array_map('intval', $days), true);
    }

    /** @param iterable<HabitLog>|null $logs */
    public function doneDates(?iterable $logs = null): array
    {
        $done = [];
        foreach ($logs ?? $this->logs as $log) {
            if ($log->count >= $this->target_per_day) {
                $done[$log->date] = true;
            }
        }

        return $done;
    }

    /**
     * Consecutive scheduled days (or weeks, for weekly habits) completed up to today.
     * Today still being open doesn't break the streak.
     */
    public function currentStreak(string $today, ?iterable $logs = null): int
    {
        $done = $this->doneDates($logs);
        $day = Carbon::parse($today);

        if ($this->frequency === 'weekly') {
            $weeks = [];
            foreach (array_keys($done) as $date) {
                $weeks[Carbon::parse($date)->startOfWeek()->toDateString()] = true;
            }
            $week = $day->copy()->startOfWeek();
            if (! isset($weeks[$week->toDateString()])) {
                $week->subWeek();
            }
            $streak = 0;
            while (isset($weeks[$week->toDateString()])) {
                $streak++;
                $week->subWeek();
            }

            return $streak;
        }

        if (! isset($done[$day->toDateString()])) {
            $day->subDay();
        }

        $streak = 0;
        for ($i = 0; $i < 400; $i++, $day->subDay()) {
            if (! $this->isScheduledOn($day)) {
                continue;
            }
            if (! isset($done[$day->toDateString()])) {
                break;
            }
            $streak++;
        }

        return $streak;
    }
}
