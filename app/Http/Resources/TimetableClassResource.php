<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TimetableClass */
class TimetableClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'term_id' => $this->term_id,
            'name' => $this->name,
            'code' => $this->code,
            'kind' => $this->kind,
            'lecturer' => $this->lecturer,
            'room' => $this->room,
            'color' => $this->color,
            'slots' => array_values($this->slots ?? []),
            'reminder_minutes' => $this->reminder_minutes,
            'show_in_calendar' => (bool) $this->show_in_calendar,
            'notes' => $this->notes,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
