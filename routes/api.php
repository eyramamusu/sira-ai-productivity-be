<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvatarController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CoachController;
use App\Http\Controllers\Api\CronController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\FocusSessionController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\HabitController;
use App\Http\Controllers\Api\InsightsController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SubtaskController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', fn () => ['status' => 'ok', 'time' => now()->toIso8601String()]);

    // Profile photos stored in the database (SIRA_AVATAR_STORAGE=database).
    Route::get('avatars/{token}', [AvatarController::class, 'show'])->where('token', '[A-Za-z0-9]{40}');

    // Scheduler trigger for hosts without cron (SIRA_CRON_SECRET).
    Route::match(['get', 'post'], 'cron/run', [CronController::class, 'run']);

    Route::middleware('throttle:auth')->prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    // Store server-to-server notifications (authenticated by shared secret or re-verified with the store).
    Route::prefix('webhooks')->group(function () {
        Route::post('google-play', [SubscriptionController::class, 'googleWebhook']);
        Route::post('app-store', [SubscriptionController::class, 'appleWebhook']);
        Route::post('revenuecat', [SubscriptionController::class, 'revenueCatWebhook']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);

        Route::get('me', [AccountController::class, 'show']);
        Route::patch('me', [AccountController::class, 'update']);
        Route::post('me/avatar', [AccountController::class, 'updateAvatar']);
        Route::delete('me/avatar', [AccountController::class, 'destroyAvatar']);
        Route::put('me/password', [AccountController::class, 'updatePassword']);
        Route::get('me/export', [AccountController::class, 'export']);
        Route::delete('me', [AccountController::class, 'destroy']);

        Route::post('devices', [DeviceController::class, 'store']);
        Route::delete('devices', [DeviceController::class, 'destroy']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read', [NotificationController::class, 'markRead']);
        Route::delete('notifications/{id}', [NotificationController::class, 'destroy'])->whereUuid('id');

        Route::get('settings', [SettingsController::class, 'show']);
        Route::patch('settings', [SettingsController::class, 'update']);

        Route::apiResource('categories', CategoryController::class)->except('show');

        Route::apiResource('tasks', TaskController::class);
        Route::post('tasks/{task}/complete', [TaskController::class, 'complete']);
        Route::post('tasks/{task}/reopen', [TaskController::class, 'reopen']);
        Route::post('tasks/{task}/subtasks', [SubtaskController::class, 'store']);
        Route::patch('tasks/{task}/subtasks/{subtask}', [SubtaskController::class, 'update']);
        Route::delete('tasks/{task}/subtasks/{subtask}', [SubtaskController::class, 'destroy']);

        Route::apiResource('habits', HabitController::class);
        Route::post('habits/{habit}/check-ins', [HabitController::class, 'checkIn']);
        Route::delete('habits/{habit}/check-ins/{date}', [HabitController::class, 'undoCheckIn']);

        Route::apiResource('goals', GoalController::class);

        Route::apiResource('focus-sessions', FocusSessionController::class)->except('show')
            ->parameters(['focus-sessions' => 'focusSession']);

        Route::get('overview', [InsightsController::class, 'overview']);
        Route::get('calendar', [InsightsController::class, 'calendar']);
        Route::get('analytics', [InsightsController::class, 'analytics']);
        Route::get('gamification', [InsightsController::class, 'gamification']);
        Route::get('sync', [SyncController::class, 'pull']);

        Route::get('subscription', [SubscriptionController::class, 'show']);
        Route::post('subscription/verify', [SubscriptionController::class, 'verify'])->middleware('throttle:auth');

        Route::middleware('premium')->group(function () {
            Route::get('analytics/advanced', [InsightsController::class, 'advancedAnalytics']);
            Route::get('reports/productivity', [InsightsController::class, 'report']);

            Route::prefix('coach')->group(function () {
                Route::get('messages', [CoachController::class, 'history']);
                Route::delete('messages', [CoachController::class, 'clearHistory']);
                Route::middleware('throttle:coach')->group(function () {
                    Route::post('chat', [CoachController::class, 'chat']);
                    Route::post('breakdown', [CoachController::class, 'breakdown']);
                    Route::post('prioritize', [CoachController::class, 'prioritize']);
                    Route::post('plan-day', [CoachController::class, 'planDay']);
                });
            });
        });
    });
});
