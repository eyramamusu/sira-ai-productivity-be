<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CalendarEvent */
class CalendarEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'all_day' => (bool) $this->all_day,
            'start_date' => $this->start_date?->toDateString(),
            'start_time' => $this->start_time,
            'end_date' => $this->end_date?->toDateString(),
            'end_time' => $this->end_time,
            'repeat_rule' => $this->repeat_rule,
            'repeat_until' => $this->repeat_until?->toDateString(),
            'reminder_minutes' => array_values($this->reminder_minutes ?? []),
            'color' => $this->color,
            'goal_id' => $this->goal_id,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
