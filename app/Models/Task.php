<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Task extends Model
{
    use HasUuids, SoftDeletes;

    public const PRIORITIES = ['low', 'medium', 'high'];

    public const REPEAT_RULES = ['none', 'daily', 'weekdays', 'weekly', 'monthly'];

    public const XP = ['low' => 5, 'medium' => 10, 'high' => 15];

    protected $fillable = [
        'id', 'title', 'description', 'priority', 'category_id', 'goal_id', 'due_date', 'start_time', 'end_time',
        'repeat_rule', 'repeat_until', 'add_to_calendar', 'position', 'recurrence_parent_id',
    ];

    protected $attributes = ['priority' => 'medium', 'repeat_rule' => 'none', 'add_to_calendar' => true, 'position' => 0];

    protected function casts(): array
    {
        return [
            'due_date' => DateOnly::class,
            'repeat_until' => DateOnly::class,
            'completed_at' => 'datetime',
            'add_to_calendar' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class)->orderBy('position');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('completed_at');
    }

    public function scopeCompleted(Builder $query): void
    {
        $query->whereNotNull('completed_at');
    }

    /** Due date of the next occurrence, or null when the series has ended. */
    public function nextOccurrence(): ?Carbon
    {
        if ($this->repeat_rule === 'none') {
            return null;
        }

        $from = $this->due_date ?? now()->startOfDay();
        $next = match ($this->repeat_rule) {
            'daily' => $from->copy()->addDay(),
            'weekdays' => $from->copy()->addWeekday(),
            'weekly' => $from->copy()->addWeek(),
            'monthly' => $from->copy()->addMonthNoOverflow(),
        };

        return $this->repeat_until && $next->gt($this->repeat_until) ? null : $next;
    }
}
