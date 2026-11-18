<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CalendarEvent extends Model
{
    use HasUuids, SoftDeletes;

    public const REPEAT_RULES = ['none', 'daily', 'weekly', 'monthly', 'yearly'];

    protected $fillable = [
        'id', 'goal_id', 'title', 'description', 'location', 'all_day', 'start_date', 'start_time',
        'end_date', 'end_time', 'repeat_rule', 'repeat_until', 'reminder_minutes', 'color',
    ];

    protected $attributes = ['all_day' => false, 'repeat_rule' => 'none', 'color' => '#2F6FA8'];

    protected function casts(): array
    {
        return [
            'all_day' => 'boolean',
            'start_date' => DateOnly::class,
            'end_date' => DateOnly::class,
            'repeat_until' => DateOnly::class,
            'reminder_minutes' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
