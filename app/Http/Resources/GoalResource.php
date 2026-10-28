<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Goal */
class GoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'target_date' => $this->target_date?->toDateString(),
            'progress' => (int) $this->progress,
            'status' => $this->status,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'tasks_count' => (int) ($this->tasks_count ?? 0),
            'completed_tasks_count' => (int) ($this->completed_tasks_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
