<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\GoalResource;
use App\Models\CalendarEvent;
use App\Models\Goal;
use App\Models\User;
use App\Services\GoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GoalController extends Controller
{
    public function __construct(private GoalService $goals) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->goals()->orderByRaw("status = 'active' desc")->orderBy('created_at');

        return GoalResource::collection($this->withCounts($query)->get());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate($this->rules(true));

        $goal = $this->replayed(Goal::class, $data['id'] ?? null, $user);
        $created = $goal === null;
        if ($created && ($data['status'] ?? 'active') === 'active') {
            $this->ensureCanActivate($user);
        }
        $goal ??= $user->goals()->make()->setRelation('user', $user);

        return $this->save($goal, $data, $created ? 201 : 200);
    }

    public function show(Request $request, Goal $goal): GoalResource
    {
        $this->own($request, $goal);

        return new GoalResource($this->withCounts(Goal::whereKey($goal->id))->first());
    }

    public function update(Request $request, Goal $goal): JsonResponse
    {
        $this->own($request, $goal);
        $data = $request->validate($this->rules(false));
        if (($data['status'] ?? null) === 'active' && $goal->status !== 'active') {
            $this->ensureCanActivate($request->user());
        }

        return $this->save($goal, $data);
    }

    public function destroy(Request $request, Goal $goal): Response
    {
        $this->own($request, $goal);
        DB::transaction(function () use ($goal) {
            $goal->tasks()->update(['goal_id' => null, 'updated_at' => now()]);
            CalendarEvent::where('goal_id', $goal->id)->update(['goal_id' => null, 'updated_at' => now()]);
            $goal->delete();
        });

        return response()->noContent();
    }

    private function save(Goal $goal, array $data, int $status = 200): JsonResponse
    {
        $unlocked = DB::transaction(function () use ($goal, $data) {
            $goal->fill(Arr::except($data, ['progress', 'status']))->save();

            return $this->goals->apply($goal, $data['progress'] ?? null, $data['status'] ?? null);
        });

        return response()->json([
            'data' => (new GoalResource($this->withCounts(Goal::whereKey($goal->id))->first()))->resolve(),
            'achievements_unlocked' => $unlocked,
        ], $status);
    }

    private function ensureCanActivate(User $user): void
    {
        $max = config('sira.free.max_active_goals');
        if (! $user->isPremium() && $user->goals()->active()->count() >= $max) {
            throw ApiException::planLimit("The free plan includes {$max} active goals. Upgrade to Premium for unlimited goals.");
        }
    }

    private function withCounts($query)
    {
        return $query->withCount(['tasks', 'tasks as completed_tasks_count' => fn ($q) => $q->whereNotNull('completed_at')]);
    }

    private function rules(bool $creating): array
    {
        return [
            'id' => ['sometimes', 'uuid'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'target_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'progress' => ['sometimes', 'integer', 'between:0,100'],
            'status' => ['sometimes', Rule::in(Goal::STATUSES)],
        ];
    }
}
