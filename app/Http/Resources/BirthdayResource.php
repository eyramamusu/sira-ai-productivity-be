<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Birthday */
class BirthdayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'month' => (int) $this->month,
            'day' => (int) $this->day,
            'birth_year' => $this->birth_year,
            'reminder_days' => array_values($this->reminder_days ?? []),
            'reminder_time' => $this->reminder_time,
            'color' => $this->color,
            'note' => $this->note,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
