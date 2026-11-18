<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A school term or semester; its classes repeat weekly between these dates. */
class TimetableTerm extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['id', 'name', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return ['starts_on' => DateOnly::class, 'ends_on' => DateOnly::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function classes(): HasMany
    {
        return $this->hasMany(TimetableClass::class, 'term_id');
    }
}
