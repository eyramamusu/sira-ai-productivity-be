<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Services\GoalService;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function __construct(private TaskService $tasks, private GoalService $goals) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $f = $request->validate([
            'view' => ['nullable', Rule::in(['today', 'upcoming', 'completed', 'overdue'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'category_id' => ['nullable', 'uuid'],
            'goal_id' => ['nullable', 'uuid'],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'status' => ['nullable', Rule::in(['open', 'completed'])],
            'search' => ['nullable', 'string', 'max:100'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $today = $user->today();

        $query = $user->tasks()->with(['category', 'subtasks'])
            ->when($f['view'] ?? null, fn ($q, $view) => match ($view) {
                // Today's list keeps overdue open tasks in view so nothing silently drops off.
                'today' => $q->where(fn ($q) => $q->where('due_date', $today)->orWhere(fn ($q) => $q->open()->where('due_date', '<', $today))),
                'upcoming' => $q->open()->where('due_date', '>', $today),
                'completed' => $q->completed(),
                'overdue' => $q->open()->where('due_date', '<', $today),
            })
            ->when($f['date'] ?? null, fn ($q, $d) => $q->where('due_date', $d))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('due_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('due_date', '<=', $d))
            ->when($f['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($f['goal_id'] ?? null, fn ($q, $id) => $q->where('goal_id', $id))
            ->when($f['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when(($f['status'] ?? null) === 'open', fn ($q) => $q->open())
            ->when(($f['status'] ?? null) === 'completed', fn ($q) => $q->completed())
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('title', 'like', '%'.addcslashes($s, '%_\\').'%')
                ->orWhere('description', 'like', '%'.addcslashes($s, '%_\\').'%')))
            ->when($f['updated_since'] ?? null, fn ($q, $d) => $q->where('updated_at', '>=', now()->parse($d)->utc()));

        if (($f['view'] ?? null) === 'completed') {
            $query->orderByDesc('completed_at');
        } else {
            $query->orderByRaw('due_date is null')->orderBy('due_date')->orderByRaw('start_time is null')->orderBy('start_time')->orderBy('position')->orderBy('created_at');
        }

        return TaskResource::collection($query->paginate($f['per_page'] ?? 50)->withQueryString());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate($this->rules($user, true));

        $task = $this->replayed(Task::class, $data['id'] ?? null, $user);
        $created = $task === null;
        if ($created) {
            $this->ensureCanAddTask($user);
            $task = new Task;
            $data['priority'] ??= $user->settingsOrDefault()->default_priority;
        }

        $unlocked = $this->save($task, $data, $user);

        return $this->respond($task, $unlocked, $created ? 201 : 200);
    }

    public function show(Request $request, Task $task): TaskResource
    {
        $this->own($request, $task);

        return new TaskResource($task->load(['category', 'subtasks']));
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $this->own($request, $task);
        $user = $request->user();
        $data = $request->validate($this->rules($user, false));

        return $this->respond($task, $this->save($task, $data, $user));
    }

    public function destroy(Request $request, Task $task): Response
    {
        $this->own($request, $task);
        $goal = $task->goal;
        $task->delete();
        $this->goals->syncFromTasks($goal?->setRelation('user', $request->user()));

        return response()->noContent();
    }

    public function complete(Request $request, Task $task): JsonResponse
    {
        $this->own($request, $task);
        $task->goal?->setRelation('user', $request->user());

        return $this->respond($task, $this->tasks->complete($task));
    }

    public function reopen(Request $request, Task $task): JsonResponse
    {
        $this->own($request, $task);
        $task->goal?->setRelation('user', $request->user());
        $this->tasks->reopen($task);

        return $this->respond($task, []);
    }

    private function ensureCanAddTask(User $user): void
    {
        $max = config('sira.free.max_tasks_per_day');
        if ($user->isPremium()) {
            return;
        }
        $addedToday = $user->tasks()->whereNull('recurrence_parent_id')
            ->where('created_at', '>=', $user->now()->startOfDay()->utc())
            ->count();
        if ($addedToday >= $max) {
            throw ApiException::planLimit("The free plan lets you add {$max} tasks a day. Upgrade to Premium for unlimited tasks.");
        }
    }

    /** Free tasks hold a few subtasks. A list that is already longer (e.g. from a lapsed Premium) may shrink but not grow. */
    private function ensureSubtasksAllowed(Task $task, array $subtasks, User $user): void
    {
        $max = config('sira.free.max_subtasks_per_task');
        $count = count($subtasks);
        if ($count <= $max || $user->isPremium()) {
            return;
        }
        if ($count > ($task->exists ? $task->subtasks()->count() : 0)) {
            throw ApiException::planLimit("The free plan includes {$max} subtasks per task. Upgrade to Premium for unlimited subtasks.");
        }
    }

    /** @return list<string> achievements unlocked by a `completed` change */
    private function save(Task $task, array $data, User $user): array
    {
        if (isset($data['subtasks'])) {
            $this->ensureSubtasksAllowed($task, $data['subtasks'], $user);
        }

        if (array_key_exists('priority', $data) && $data['priority'] === null) {
            unset($data['priority']);
        }

        return DB::transaction(function () use ($task, $data, $user) {
            $previousGoal = $task->goal_id;
            $task->fill(Arr::except($data, ['subtasks', 'completed']));
            $task->user()->associate($user);
            $task->save();

            if (array_key_exists('subtasks', $data)) {
                $this->tasks->syncSubtasks($task, $data['subtasks'] ?? []);
            }

            $task->load('goal');
            $task->goal?->setRelation('user', $user);
            $unlocked = [];
            if (array_key_exists('completed', $data)) {
                $data['completed'] ? $unlocked = $this->tasks->complete($task) : $this->tasks->reopen($task);
            }

            $this->goals->syncFromTasks($task->goal);
            if ($previousGoal && $previousGoal !== $task->goal_id) {
                $this->goals->syncFromTasks($user->goals()->find($previousGoal)?->setRelation('user', $user));
            }

            return $unlocked;
        });
    }

    private function respond(Task $task, array $unlocked, int $status = 200): JsonResponse
    {
        $task->refresh()->load(['category', 'subtasks']);

        return response()->json([
            'data' => (new TaskResource($task))->resolve(),
            'xp' => (int) $task->user->xp,
            'achievements_unlocked' => $unlocked,
        ], $status);
    }

    private function rules(User $user, bool $creating): array
    {
        $owned = fn (string $table) => Rule::exists($table, 'id')->where('user_id', $user->id)->whereNull('deleted_at');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'id' => ['sometimes', 'uuid'],
            'title' => [$required, 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'priority' => ['sometimes', 'nullable', Rule::in(Task::PRIORITIES)],
            'category_id' => ['sometimes', 'nullable', 'uuid', $owned('categories')],
            'goal_id' => ['sometimes', 'nullable', 'uuid', $owned('goals')],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'repeat_rule' => ['sometimes', Rule::in(Task::REPEAT_RULES)],
            'repeat_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'add_to_calendar' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer'],
            'completed' => ['sometimes', 'boolean'],
            'subtasks' => ['sometimes', 'array', 'max:50'],
            'subtasks.*.id' => ['sometimes', 'nullable', 'uuid'],
            'subtasks.*.title' => ['required', 'string', 'max:200'],
            'subtasks.*.completed' => ['sometimes', 'boolean'],
            'subtasks.*.position' => ['sometimes', 'integer'],
        ];
    }
}
