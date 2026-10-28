<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\GamificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SubtaskController extends Controller
{
    public function __construct(private GamificationService $gamification) {}

    public function store(Request $request, Task $task): JsonResponse
    {
        $this->own($request, $task);
        $data = $request->validate([
            'id' => ['sometimes', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'completed' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer'],
        ]);

        $existing = isset($data['id']) ? Subtask::find($data['id']) : null;
        abort_if($existing && $existing->task_id !== $task->id, 409, 'This id is already used by another item.');
        $data['position'] ??= (int) $task->subtasks()->max('position') + 1;

        $subtask = $existing ?? $task->subtasks()->make();
        $unlocked = $this->apply($request, $task, $subtask, $data);

        return response()->json(['data' => $this->present($subtask), 'achievements_unlocked' => $unlocked], $existing ? 200 : 201);
    }

    public function update(Request $request, Task $task, Subtask $subtask): JsonResponse
    {
        $this->own($request, $task);
        abort_unless($subtask->task_id === $task->id, 404);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'completed' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer'],
        ]);

        $unlocked = $this->apply($request, $task, $subtask, $data);

        return response()->json(['data' => $this->present($subtask), 'achievements_unlocked' => $unlocked]);
    }

    public function destroy(Request $request, Task $task, Subtask $subtask): Response
    {
        $this->own($request, $task);
        abort_unless($subtask->task_id === $task->id, 404);
        $subtask->delete();

        return response()->noContent();
    }

    /** Ticking a subtask off earns 2 XP; unticking it takes them back. */
    private function apply(Request $request, Task $task, Subtask $subtask, array $data): array
    {
        $was = (bool) $subtask->completed;
        $subtask->fill($data)->save();
        $user = $request->user();

        if ($subtask->completed && ! $was) {
            return $this->gamification->award($user, Subtask::XP, 'subtask_completed', 'subtask', $subtask->id);
        }
        if (! $subtask->completed && $was) {
            $this->gamification->revoke($user, 'subtask_completed', 'subtask', $subtask->id);
        }

        return [];
    }

    private function present(Subtask $s): array
    {
        return ['id' => $s->id, 'task_id' => $s->task_id, 'title' => $s->title, 'completed' => (bool) $s->completed, 'position' => (int) $s->position];
    }
}
