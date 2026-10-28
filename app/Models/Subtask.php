<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subtask extends Model
{
    use HasUuids;

    public const XP = 2;

    protected $fillable = ['id', 'title', 'completed', 'position'];

    protected $attributes = ['completed' => false, 'position' => 0];

    // Subtask edits bump the parent's updated_at so /sync picks them up.
    protected $touches = ['task'];

    protected function casts(): array
    {
        return ['completed' => 'boolean', 'position' => 'integer'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }
}
