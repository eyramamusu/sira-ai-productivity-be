<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Birthday extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['id', 'name', 'month', 'day', 'birth_year', 'reminder_days', 'reminder_time', 'color', 'note'];

    protected $attributes = ['reminder_time' => '09:00', 'color' => '#B8405E'];

    protected function casts(): array
    {
        return ['month' => 'integer', 'day' => 'integer', 'birth_year' => 'integer', 'reminder_days' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
