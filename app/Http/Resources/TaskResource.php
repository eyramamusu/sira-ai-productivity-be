<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Task */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->relationLoaded('category') ? $this->category : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'category_id' => $this->category_id,
            'category' => $category ? ['id' => $category->id, 'name' => $category->name, 'color' => $category->color, 'icon' => $category->icon] : null,
            'goal_id' => $this->goal_id,
            'due_date' => $this->due_date?->toDateString(),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'repeat_rule' => $this->repeat_rule,
            'repeat_until' => $this->repeat_until?->toDateString(),
            'recurrence_parent_id' => $this->recurrence_parent_id,
            'add_to_calendar' => (bool) $this->add_to_calendar,
            'position' => (int) $this->position,
            'completed' => $this->completed_at !== null,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'subtasks' => $this->relationLoaded('subtasks')
                ? $this->subtasks->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'completed' => (bool) $s->completed, 'position' => (int) $s->position])->values()
                : [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
