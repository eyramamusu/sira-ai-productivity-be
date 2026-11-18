<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A course on the student's weekly timetable, meeting in one or more weekly slots. */
class TimetableClass extends Model
{
    use HasUuids, SoftDeletes;

    public const KINDS = ['lecture', 'tutorial', 'lab', 'seminar', 'other'];

    protected $fillable = [
        'id', 'term_id', 'name', 'code', 'kind', 'lecturer', 'room', 'color', 'slots',
        'reminder_minutes', 'show_in_calendar', 'notes',
    ];

    protected $attributes = ['kind' => 'lecture', 'color' => '#5B48A2', 'show_in_calendar' => true];

    protected function casts(): array
    {
        return ['slots' => 'array', 'reminder_minutes' => 'integer', 'show_in_calendar' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(TimetableTerm::class, 'term_id');
    }
}
