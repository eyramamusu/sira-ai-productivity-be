<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgendaEntryResource;
use App\Http\Resources\BirthdayResource;
use App\Http\Resources\CalendarEventResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\FocusSessionResource;
use App\Http\Resources\GoalResource;
use App\Http\Resources\HabitResource;
use App\Http\Resources\TaskResource;
use App\Http\Resources\TimetableClassResource;
use App\Http\Resources\TimetableTermResource;
use App\Http\Resources\TimetableVacationResource;
use App\Http\Resources\UserResource;
use App\Notifications\SecurityAlert;
use App\Support\AvatarStore;
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
            'onboarding_completed' => ['sometimes', 'boolean'],
        ]);

        $user->update($data);

        return new UserResource($user);
    }

    /** Replaces the profile photo. The app downsizes it to a JPEG before upload. */
    public function updateAvatar(Request $request, AvatarStore $avatars): UserResource
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
        ]);

        $user = $request->user();
        $previous = $user->avatar_path;
        $avatar = $avatars->store($user, $request->file('avatar'));
        $user->update(['avatar_path' => $avatar['path'], 'avatar_url' => $avatar['url']]);
        $avatars->delete($previous);

        return new UserResource($user);
    }

    public function destroyAvatar(Request $request, AvatarStore $avatars): UserResource
    {
        $user = $request->user();
        $avatars->delete($user->avatar_path);
        $user->update(['avatar_path' => null, 'avatar_url' => null]);

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
        $user->notify(new SecurityAlert(SecurityAlert::PASSWORD_CHANGED));

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
            'events' => CalendarEventResource::collection($user->calendarEvents()->orderBy('start_date')->get())->resolve($request),
            'birthdays' => BirthdayResource::collection($user->birthdays()->get())->resolve($request),
            'timetable_terms' => TimetableTermResource::collection($user->timetableTerms()->get())->resolve($request),
            'timetable_classes' => TimetableClassResource::collection($user->timetableClasses()->get())->resolve($request),
            'timetable_vacations' => TimetableVacationResource::collection($user->timetableVacations()->get())->resolve($request),
            'agenda_entries' => AgendaEntryResource::collection($user->agendaEntries()->orderBy('date')->get())->resolve($request),
            'achievements' => $user->achievements()->get(['achievement_key', 'unlocked_at']),
            'xp_history' => $user->xpEvents()->orderBy('id')->get(['amount', 'reason', 'created_at']),
            'subscriptions' => $user->subscriptions()->get()->map->toApi(),
            'coach_messages' => $user->coachMessages()->orderBy('id')->get()->map->toApi(),
            'notifications' => $user->notifications()->latest()->get()->map(fn ($n) => NotificationController::toApi($n)),
            'devices' => $user->deviceTokens()->get(['platform', 'last_seen_at', 'created_at']),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Permanently deletes the account; the foreign keys cascade to every row the user owns. */
    public function destroy(Request $request, AvatarStore $avatars): JsonResponse
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

        // Database avatars go with the user row (cascade); this removes a disk file.
        $avatars->delete($user->avatar_path);

        return response()->json([
            'message' => 'Your account and all of its data have been deleted.',
            'store_subscription_notice' => $storeSubscription
                ? 'Deleting your account does not cancel your store subscription. Cancel it in Google Play or the App Store to stop future charges.'
                : null,
        ]);
    }
}
