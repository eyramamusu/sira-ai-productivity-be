<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One occurrence of a class or event: whether the user attended or finished it, and that
 * day's notes (for example the lecture notes for this week's Maths class).
 */
class AgendaEntry extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPES = ['class', 'event'];

    protected $fillable = ['id', 'item_type', 'item_id', 'date', 'slot_start', 'done_at', 'notes'];

    protected function casts(): array
    {
        return ['date' => DateOnly::class, 'done_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
