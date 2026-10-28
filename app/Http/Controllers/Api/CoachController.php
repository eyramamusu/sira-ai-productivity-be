<?php

namespace App\Http\Controllers\Api;

use App\Ai\CoachService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CoachController extends Controller
{
    public function __construct(private CoachService $coach) {}

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $messages = $user->coachMessages()->latest('id')->limit(50)->get()->reverse()->values()->map->toApi();

        return response()->json(['data' => $messages, 'usage' => $this->coach->usage($user)]);
    }

    public function clearHistory(Request $request): Response
    {
        $request->user()->coachMessages()->delete();

        return response()->noContent();
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);

        return $this->respond($request, ['reply' => $this->coach->chat($request->user(), $data['message'])]);
    }

    public function breakdown(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'goal' => ['required_without:goal_id', 'nullable', 'string', 'max:500'],
            'goal_id' => ['required_without:goal', 'nullable', 'uuid'],
        ]);

        if (! empty($data['goal_id'])) {
            $goal = $user->goals()->findOrFail($data['goal_id']);

            return $this->respond($request, $this->coach->breakdownGoal($user, $goal));
        }

        return $this->respond($request, $this->coach->breakdown($user, $data['goal']));
    }

    public function prioritize(Request $request): JsonResponse
    {
        return $this->respond($request, $this->coach->prioritize($request->user()));
    }

    public function planDay(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return $this->respond($request, $this->coach->planDay($request->user(), $data['date'] ?? null));
    }

    private function respond(Request $request, array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'usage' => $this->coach->usage($request->user())]);
    }
}
