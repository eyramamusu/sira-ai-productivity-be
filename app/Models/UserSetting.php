<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    protected $fillable = [
        'theme', 'color_theme', 'notifications_enabled', 'habit_reminders', 'task_reminders', 'default_priority',
        'show_completed_tasks', 'default_due_time', 'week_starts_on', 'focus_minutes', 'break_minutes', 'daily_task_goal',
    ];

    // Mirrors the column defaults so a freshly created row is complete without a reload.
    protected $attributes = [
        'theme' => 'system',
        'color_theme' => 'sira',
        'notifications_enabled' => true,
        'habit_reminders' => true,
        'task_reminders' => true,
        'default_priority' => 'medium',
        'show_completed_tasks' => true,
        'default_due_time' => '09:00',
        'week_starts_on' => 1,
        'focus_minutes' => 25,
        'break_minutes' => 5,
        'daily_task_goal' => 5,
    ];

    protected function casts(): array
    {
        return [
            'notifications_enabled' => 'boolean',
            'habit_reminders' => 'boolean',
            'task_reminders' => 'boolean',
            'show_completed_tasks' => 'boolean',
            'week_starts_on' => 'integer',
            'focus_minutes' => 'integer',
            'break_minutes' => 'integer',
            'daily_task_goal' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toApi(): array
    {
        return collect($this->only(array_merge($this->fillable, ['updated_at'])))
            ->map(fn ($v, $k) => $k === 'updated_at' ? $v?->toIso8601String() : $v)
            ->all();
    }
}
