<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FocusSessionResource;
use App\Models\FocusSession;
use App\Models\User;
use App\Services\GamificationService;
use App\Services\StatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FocusSessionController extends Controller
{
    public function __construct(private GamificationService $gamification, private StatsService $stats) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $page = $user->focusSessions()->with('task')
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('started_at', '>=', $this->stats->utcBounds($user, $d, $d)[0]))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('started_at', '<=', $this->stats->utcBounds($user, $d, $d)[1]))
            ->latest('started_at')
            ->paginate($f['per_page'] ?? 50)
            ->withQueryString()
            ->through(fn (FocusSession $s) => (new FocusSessionResource($s))->resolve($request));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate($this->rules($user, true));

        $session = $this->replayed(FocusSession::class, $data['id'] ?? null, $user);
        $created = $session === null;

        return $this->save($session ?? $user->focusSessions()->make(), $data, $user, $created ? 201 : 200);
    }

    public function update(Request $request, FocusSession $focusSession): JsonResponse
    {
        $this->own($request, $focusSession);
        $user = $request->user();

        return $this->save($focusSession, $request->validate($this->rules($user, false)), $user);
    }

    public function destroy(Request $request, FocusSession $focusSession): Response
    {
        $this->own($request, $focusSession);
        DB::transaction(function () use ($focusSession, $request) {
            $this->gamification->revoke($request->user(), 'focus_session', 'focus', $focusSession->id);
            $focusSession->delete();
        });

        return response()->noContent();
    }

    /** XP always matches the session's current length, so edits re-price it. */
    private function save(FocusSession $session, array $data, User $user, int $status = 200): JsonResponse
    {
        // Clients send local offsets; store UTC instants.
        foreach (['started_at', 'ended_at'] as $key) {
            if (! empty($data[$key])) {
                $data[$key] = Carbon::parse($data[$key])->utc();
            }
        }

        $unlocked = DB::transaction(function () use ($session, $data, $user) {
            $session->fill($data);
            $session->user()->associate($user);
            $session->save();

            return $this->gamification->award($user, $session->xpValue(), 'focus_session', 'focus', $session->id);
        });

        return response()->json([
            'data' => (new FocusSessionResource($session->load('task')))->resolve(),
            'xp' => (int) $user->xp,
            'achievements_unlocked' => $unlocked,
        ], $status);
    }

    private function rules(User $user, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'id' => ['sometimes', 'uuid'],
            'task_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('tasks', 'id')->where('user_id', $user->id)],
            'type' => ['sometimes', Rule::in(['focus', 'break'])],
            'started_at' => [$required, 'date'],
            'ended_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:started_at'],
            'planned_seconds' => ['sometimes', 'integer', 'between:0,86400'],
            'duration_seconds' => [$required, 'integer', 'between:0,86400'],
            'completed' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
