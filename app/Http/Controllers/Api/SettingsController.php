<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->settingsOrDefault()->toApi()]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'theme' => ['sometimes', Rule::in(['light', 'dark', 'system'])],
            'color_theme' => ['sometimes', Rule::in(config('sira.color_themes'))],
            'notifications_enabled' => ['sometimes', 'boolean'],
            'habit_reminders' => ['sometimes', 'boolean'],
            'task_reminders' => ['sometimes', 'boolean'],
            'default_priority' => ['sometimes', Rule::in(Task::PRIORITIES)],
            'show_completed_tasks' => ['sometimes', 'boolean'],
            'default_due_time' => ['sometimes', 'date_format:H:i'],
            'week_starts_on' => ['sometimes', 'integer', 'between:0,6'],
            'focus_minutes' => ['sometimes', 'integer', 'between:1,180'],
            'break_minutes' => ['sometimes', 'integer', 'between:1,60'],
            'daily_task_goal' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        if (isset($data['color_theme']) && $data['color_theme'] !== config('sira.free_color_theme') && ! $user->isPremium()) {
            throw ApiException::premiumRequired('Colour themes are a Premium feature.');
        }

        $settings = $user->settingsOrDefault();
        $settings->update($data);

        return response()->json(['data' => $settings->toApi()]);
    }
}
