<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The streak is computed from all loaded logs; the payload only carries the last `$days` days of them.
 *
 * @mixin \App\Models\Habit
 */
class HabitResource extends JsonResource
{
    public static int $days = 7;

    public function toArray(Request $request): array
    {
        $today = $request->user()?->today() ?? now()->toDateString();
        $since = now()->parse($today)->subDays(max(1, static::$days) - 1)->toDateString();
        $logs = $this->relationLoaded('logs') ? $this->logs : collect();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon,
            'color' => $this->color,
            'frequency' => $this->frequency,
            'days_of_week' => array_map('intval', $this->days_of_week ?? []),
            'target_per_day' => (int) $this->target_per_day,
            'reminder_time' => $this->reminder_time,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'current_streak' => $this->currentStreak($today, $logs),
            'logs' => $logs->filter(fn ($l) => $l->date >= $since)->map(fn ($l) => ['date' => $l->date, 'count' => (int) $l->count])->values(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
