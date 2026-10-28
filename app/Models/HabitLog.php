<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** `date` is kept as a plain `Y-m-d` string: a check-in belongs to a calendar day, not an instant. */
class HabitLog extends Model
{
    protected $fillable = ['date', 'count'];

    protected function casts(): array
    {
        return ['count' => 'integer'];
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class)->withTrashed();
    }

    /** XP ledger source id for the check-in. */
    public static function sourceId(string $habitId, string $date): string
    {
        return "{$habitId}@{$date}";
    }
}
