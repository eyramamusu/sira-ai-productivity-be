<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\FocusSessionResource;
use App\Http\Resources\GoalResource;
use App\Http\Resources\HabitResource;
use App\Http\Resources\TaskResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AccountController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(Request $request): UserResource
    {
        $user = $request->user();
        if ($request->has('email')) {
            $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'avatar_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'onboarding_completed' => ['sometimes', 'boolean'],
        ]);

        $user->update($data);

        return new UserResource($user);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }

        $user->update(['password' => $data['password']]);

        // Keep this device signed in, sign out every other one.
        $current = $user->currentAccessToken();
        $user->tokens()->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->getKey()))->delete();

        return response()->json(['message' => 'Password updated. Other devices have been signed out.']);
    }

    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        HabitResource::$days = 3660;

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'profile' => (new UserResource($user))->resolve($request),
            'settings' => $user->settingsOrDefault()->toApi(),
            'categories' => CategoryResource::collection($user->categories()->get())->resolve($request),
            'tasks' => TaskResource::collection($user->tasks()->with(['category', 'subtasks'])->orderBy('created_at')->get())->resolve($request),
            'habits' => HabitResource::collection($user->habits()->with('logs')->get())->resolve($request),
            'goals' => GoalResource::collection($user->goals()->get())->resolve($request),
            'focus_sessions' => FocusSessionResource::collection($user->focusSessions()->orderBy('started_at')->get())->resolve($request),
            'achievements' => $user->achievements()->get(['achievement_key', 'unlocked_at']),
            'xp_history' => $user->xpEvents()->orderBy('id')->get(['amount', 'reason', 'created_at']),
            'subscriptions' => $user->subscriptions()->get()->map->toApi(),
            'coach_messages' => $user->coachMessages()->orderBy('id')->get()->map->toApi(),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Permanently deletes the account; the foreign keys cascade to every row the user owns. */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Your password is incorrect.']);
        }

        $storeSubscription = $user->subscriptions()->entitled()->where('auto_renewing', true)->where('platform', '!=', 'manual')->exists();

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            // Reset tokens are keyed by email, so the user_id cascade doesn't reach them.
            Password::broker()->deleteToken($user);
            $user->delete();
        });

        return response()->json([
            'message' => 'Your account and all of its data have been deleted.',
            'store_subscription_notice' => $storeSubscription
                ? 'Deleting your account does not cancel your store subscription. Cancel it in Google Play or the App Store to stop future charges.'
                : null,
        ]);
    }
}
