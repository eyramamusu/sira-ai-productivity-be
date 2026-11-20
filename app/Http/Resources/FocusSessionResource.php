<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FocusSession */
class FocusSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $task = $this->relationLoaded('task') ? $this->task : null;

        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'event_id' => $this->event_id,
            'class_id' => $this->class_id,
            'type' => $this->type,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'planned_seconds' => (int) $this->planned_seconds,
            'duration_seconds' => (int) $this->duration_seconds,
            'completed' => (bool) $this->completed,
            'note' => $this->note,
            'task' => $task ? ['id' => $task->id, 'title' => $task->title] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
