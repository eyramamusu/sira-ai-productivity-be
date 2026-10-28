<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoachMessage extends Model
{
    protected $fillable = ['role', 'content'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toApi(): array
    {
        return ['id' => $this->id, 'role' => $this->role, 'content' => $this->content, 'created_at' => $this->created_at?->toIso8601String()];
    }
}
