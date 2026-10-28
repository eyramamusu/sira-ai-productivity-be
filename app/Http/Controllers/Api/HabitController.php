<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\HabitResource;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;
use App\Services\GamificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class HabitController extends Controller
{
    public function __construct(private GamificationService $gamification) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        HabitResource::$days = (int) ($request->validate(['days' => ['nullable', 'integer', 'between:1,366']])['days'] ?? 7);

        return HabitResource::collection($this->withHistory($request->user()->habits())->orderBy('created_at')->get());
    }

    public function store(Request $request): HabitResource
    {
        $user = $request->user();
        $data = $request->validate($this->rules(true));

        $habit = $this->replayed(Habit::class, $data['id'] ?? null, $user);
        if (! $habit && ! ($data['archived'] ?? false)) {
            $this->ensureCanActivate($user);
        }

        $habit ??= $user->habits()->make();
        $habit->fill($this->attributes($data, $habit))->save();

        return $this->present($habit);
    }

    public function show(Request $request, Habit $habit): HabitResource
    {
        $this->own($request, $habit);

        return $this->present($habit);
    }

    public function update(Request $request, Habit $habit): HabitResource
    {
        $this->own($request, $habit);
        $data = $request->validate($this->rules(false));

        if (array_key_exists('archived', $data) && ! $data['archived'] && $habit->archived_at) {
            $this->ensureCanActivate($request->user());
        }
        $habit->fill($this->attributes($data, $habit))->save();

        return $this->present($habit);
    }

    public function destroy(Request $request, Habit $habit): Response
    {
        $this->own($request, $habit);
        $habit->delete();

        return response()->noContent();
    }

    /** Mark a day done (+5 XP the first time it reaches the daily target). */
    public function checkIn(Request $request, Habit $habit): JsonResponse
    {
        $this->own($request, $habit);
        $user = $request->user();
        $today = $user->today();
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'count' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $date = $data['date'] ?? $today;

        $log = $habit->logs()->firstOrNew(['date' => $date]);
        $log->count = $data['count'] ?? $habit->target_per_day;
        $log->save();
        $habit->touch();

        $unlocked = $log->count >= $habit->target_per_day
            ? $this->gamification->award($user, Habit::XP, 'habit_checkin', 'habit', HabitLog::sourceId($habit->id, $date))
            : [];

        return response()->json([
            'data' => ['date' => $date, 'count' => $log->count],
            'current_streak' => $this->streak($habit, $today),
            'achievements_unlocked' => $unlocked,
        ]);
    }

    public function undoCheckIn(Request $request, Habit $habit, string $date): JsonResponse
    {
        $this->own($request, $habit);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);
        $user = $request->user();

        $habit->logs()->where('date', $date)->delete();
        $habit->touch();
        $this->gamification->revoke($user, 'habit_checkin', 'habit', HabitLog::sourceId($habit->id, $date));

        return response()->json(['current_streak' => $this->streak($habit, $user->today())]);
    }

    private function ensureCanActivate(User $user): void
    {
        $max = config('sira.free.max_active_habits');
        if (! $user->isPremium() && $user->habits()->active()->count() >= $max) {
            throw ApiException::planLimit("The free plan includes {$max} active habits. Upgrade to Premium for unlimited habits.");
        }
    }

    private function attributes(array $data, Habit $habit): array
    {
        $attrs = Arr::except($data, ['archived']);
        if (array_key_exists('archived', $data)) {
            $attrs['archived_at'] = $data['archived'] ? ($habit->archived_at ?? now()) : null;
        }

        return $attrs;
    }

    private function present(Habit $habit): HabitResource
    {
        HabitResource::$days = 7;
        $habit->load(['logs' => fn ($q) => $q->where('date', '>=', Carbon::now()->subDays(400)->toDateString())]);

        return new HabitResource($habit);
    }

    private function streak(Habit $habit, string $today): int
    {
        return $habit->currentStreak($today, $habit->logs()->where('date', '>=', Carbon::parse($today)->subDays(400)->toDateString())->get());
    }

    private function withHistory($query)
    {
        return $query->with(['logs' => fn ($q) => $q->where('date', '>=', Carbon::now()->subDays(400)->toDateString())]);
    }

    private function rules(bool $creating): array
    {
        return [
            'id' => ['sometimes', 'uuid'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:40'],
            'color' => ['sometimes', 'nullable', 'string', 'max:9'],
            'frequency' => ['sometimes', Rule::in(['daily', 'weekly'])],
            'days_of_week' => ['sometimes', 'nullable', 'array', 'max:7'],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'target_per_day' => ['sometimes', 'integer', 'between:1,20'],
            'reminder_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }
}
