<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\FocusSessionResource;
use App\Http\Resources\GoalResource;
use App\Http\Resources\HabitResource;
use App\Http\Resources\TaskResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Offline sync. Without `since`: a full snapshot (open tasks plus 60 days of history).
 * With `since`: every row changed at or after that time, including soft-deleted ones.
 */
class SyncController extends Controller
{
    private const HISTORY_DAYS = 60;

    public function pull(Request $request): JsonResponse
    {
        $user = $request->user();
        $since = $request->validate(['since' => ['nullable', 'date']])['since'] ?? null;
        // Read the clock first so nothing written during this request is skipped next time.
        $serverTime = now();
        $sinceUtc = $since ? Carbon::parse($since)->utc() : null;
        $history = now()->subDays(self::HISTORY_DAYS);
        $logsSince = Carbon::parse($user->today())->subDays(400)->toDateString();

        $changed = fn ($query) => $sinceUtc
            ? $query->withTrashed()->where('updated_at', '>=', $sinceUtc)
            : $query;

        $tasks = $changed($user->tasks()->with(['category', 'subtasks']))
            ->when(! $sinceUtc, fn ($q) => $q->where(fn ($q) => $q->open()->orWhere('completed_at', '>=', $history)->orWhere('due_date', '>=', $history->toDateString())))
            ->get();
        $sessions = $changed($user->focusSessions())
            ->when(! $sinceUtc, fn ($q) => $q->where('started_at', '>=', $history))
            ->get();

        HabitResource::$days = self::HISTORY_DAYS;

        return response()->json([
            'server_time' => $serverTime->toIso8601String(),
            'full' => $sinceUtc === null,
            'tasks' => TaskResource::collection($tasks)->resolve($request),
            'categories' => CategoryResource::collection($changed($user->categories())->orderBy('created_at')->get())->resolve($request),
            'habits' => HabitResource::collection($changed($user->habits())->with(['logs' => fn ($q) => $q->where('date', '>=', $logsSince)])->get())->resolve($request),
            'goals' => GoalResource::collection($changed($user->goals())->withCount(['tasks', 'tasks as completed_tasks_count' => fn ($q) => $q->whereNotNull('completed_at')])->get())->resolve($request),
            'focus_sessions' => FocusSessionResource::collection($sessions)->resolve($request),
            'settings' => $user->settingsOrDefault()->toApi(),
        ]);
    }
}
