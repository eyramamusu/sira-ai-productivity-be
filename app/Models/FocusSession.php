<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FocusSession extends Model
{
    use HasUuids, SoftDeletes;

    /** 1 XP per 5 focused minutes, capped per session. */
    public const SECONDS_PER_XP = 300;

    public const MAX_XP = 30;

    protected $fillable = ['id', 'task_id', 'type', 'started_at', 'ended_at', 'planned_seconds', 'duration_seconds', 'completed', 'note'];

    protected $attributes = ['type' => 'focus', 'planned_seconds' => 1500, 'duration_seconds' => 0, 'completed' => false];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'planned_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'completed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function xpValue(): int
    {
        return $this->type === 'focus' ? min(self::MAX_XP, intdiv($this->duration_seconds, self::SECONDS_PER_XP)) : 0;
    }
}
