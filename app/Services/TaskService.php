<?php

namespace App\Services;

use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function __construct(private GamificationService $gamification, private GoalService $goals) {}

    /** @return list<string> achievements unlocked */
    public function complete(Task $task): array
    {
        if ($task->isCompleted()) {
            return [];
        }

        return DB::transaction(function () use ($task) {
            $task->forceFill(['completed_at' => now()])->save();
            $this->spawnNextOccurrence($task);

            $unlocked = $this->gamification->award($task->user, Task::XP[$task->priority] ?? Task::XP['medium'], 'task_completed', 'task', $task->id);

            return array_values(array_unique([...$unlocked, ...$this->goals->syncFromTasks($task->goal)]));
        });
    }

    public function reopen(Task $task): void
    {
        if (! $task->isCompleted()) {
            return;
        }

        DB::transaction(function () use ($task) {
            $task->forceFill(['completed_at' => null])->save();
            $this->gamification->revoke($task->user, 'task_completed', 'task', $task->id);
            $this->goals->syncFromTasks($task->goal);
        });
    }

    /** Replace the subtask list, keeping rows whose ids are sent back. Bulk edits don't award XP. */
    public function syncSubtasks(Task $task, array $items): void
    {
        $keep = [];
        foreach (array_values($items) as $i => $item) {
            $attrs = ['title' => $item['title'], 'completed' => (bool) ($item['completed'] ?? false), 'position' => $item['position'] ?? $i];
            $existing = isset($item['id']) ? $task->subtasks()->whereKey($item['id'])->first() : null;
            if ($existing) {
                $existing->update($attrs);
                $keep[] = $existing->id;
            } elseif (isset($item['id']) && Subtask::whereKey($item['id'])->exists()) {
                $keep[] = $task->subtasks()->create($attrs)->id; // id taken by another task: let the server pick one
            } else {
                $keep[] = $task->subtasks()->create($attrs + (isset($item['id']) ? ['id' => $item['id']] : []))->id;
            }
        }
        $task->subtasks()->whereNotIn('id', $keep)->delete();
    }

    /** Recurring tasks get their next occurrence once, however often the task is reopened and completed again. */
    private function spawnNextOccurrence(Task $task): void
    {
        $next = $task->nextOccurrence();
        if (! $next || Task::withTrashed()->where('recurrence_parent_id', $task->id)->exists()) {
            return;
        }

        $copy = $task->replicate(['id', 'completed_at', 'recurrence_parent_id', 'created_at', 'updated_at', 'deleted_at']);
        $copy->due_date = $next;
        $copy->recurrence_parent_id = $task->id;
        $copy->save();

        foreach ($task->subtasks as $subtask) {
            $copy->subtasks()->create(['title' => $subtask->title, 'position' => $subtask->position, 'completed' => false]);
        }
    }
}
