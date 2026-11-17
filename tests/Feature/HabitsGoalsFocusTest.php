<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HabitsGoalsFocusTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_plan_is_limited_to_three_active_habits(): void
    {
        $user = $this->signIn();
        foreach (['Water', 'Read', 'Walk'] as $name) {
            $this->postJson('/api/v1/habits', ['name' => $name])->assertCreated();
        }

        $this->postJson('/api/v1/habits', ['name' => 'Meditate'])->assertForbidden()->assertJsonPath('code', 'plan_limit_reached');

        $this->makePremium($user);
        $this->postJson('/api/v1/habits', ['name' => 'Meditate'])->assertCreated();
    }

    public function test_expired_premium_keeps_existing_habits(): void
    {
        $user = $this->signIn();
        $sub = $this->makePremium($user);
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/habits', ['name' => "Habit {$i}"])->assertCreated();
        }

        $sub->update(['expires_at' => now()->subDay()]);

        $this->getJson('/api/v1/habits')->assertOk()->assertJsonCount(5, 'data');
        $this->postJson('/api/v1/habits', ['name' => 'Sixth'])->assertForbidden();
    }

    public function test_check_ins_build_a_streak(): void
    {
        Carbon::setTestNow('2026-05-12 10:00:00');
        $this->signIn();
        $id = $this->postJson('/api/v1/habits', ['name' => 'Workout'])->json('data.id');

        foreach (['2026-05-10', '2026-05-11', '2026-05-12'] as $date) {
            $this->postJson("/api/v1/habits/{$id}/check-ins", ['date' => $date])->assertOk();
        }
        $this->getJson("/api/v1/habits/{$id}")->assertJsonPath('data.current_streak', 3);

        $this->postJson("/api/v1/habits/{$id}/check-ins", ['date' => '2026-05-13'])->assertUnprocessable();

        $this->deleteJson("/api/v1/habits/{$id}/check-ins/2026-05-11")->assertOk()->assertJsonPath('current_streak', 1);
    }

    public function test_goal_limit_and_completion_xp(): void
    {
        $user = $this->signIn();
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/goals', ['title' => "Goal {$i}"])->assertCreated();
        }
        $this->postJson('/api/v1/goals', ['title' => 'Goal 4'])->assertForbidden();

        $goalId = $user->goals()->first()->id;
        $this->patchJson("/api/v1/goals/{$goalId}", ['progress' => 100])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertGreaterThanOrEqual(50, $user->fresh()->xp);
    }

    public function test_goal_progress_follows_linked_tasks(): void
    {
        $user = $this->signIn();
        $goalId = $this->postJson('/api/v1/goals', ['title' => 'Launch'])->json('data.id');
        $a = $this->postJson('/api/v1/tasks', ['title' => 'A', 'goal_id' => $goalId])->json('data.id');
        $this->postJson('/api/v1/tasks', ['title' => 'B', 'goal_id' => $goalId]);

        $this->postJson("/api/v1/tasks/{$a}/complete");
        $this->getJson("/api/v1/goals/{$goalId}")->assertJsonPath('data.progress', 50);
    }

    public function test_focus_sessions_record_time_and_xp(): void
    {
        $user = $this->signIn();
        $res = $this->postJson('/api/v1/focus-sessions', [
            'started_at' => now()->subMinutes(25)->toIso8601String(),
            'ended_at' => now()->toIso8601String(),
            'planned_seconds' => 1500,
            'duration_seconds' => 1500,
            'completed' => true,
        ])->assertCreated();

        $this->assertSame(5, $user->fresh()->xp);
        $this->getJson('/api/v1/focus-sessions')->assertOk()->assertJsonPath('total', 1);

        $this->deleteJson('/api/v1/focus-sessions/'.$res->json('data.id'))->assertNoContent();
        $this->assertSame(0, $user->fresh()->xp);
    }

    public function test_settings_round_trip(): void
    {
        $this->signIn();
        $this->getJson('/api/v1/settings')->assertOk()->assertJsonPath('data.theme', 'system');
        $this->patchJson('/api/v1/settings', ['theme' => 'dark', 'default_due_time' => '08:30', 'break_minutes' => 10])
            ->assertOk()->assertJsonPath('data.theme', 'dark')->assertJsonPath('data.break_minutes', 10);
        $this->patchJson('/api/v1/settings', ['theme' => 'neon'])->assertUnprocessable();
    }

    public function test_premium_colour_themes_need_premium(): void
    {
        $user = $this->signIn();
        $this->getJson('/api/v1/settings')->assertJsonPath('data.color_theme', 'sira');
        $this->patchJson('/api/v1/settings', ['color_theme' => 'ocean'])
            ->assertForbidden()->assertJsonPath('code', 'premium_required');
        $this->patchJson('/api/v1/settings', ['color_theme' => 'sira'])->assertOk();
        $this->patchJson('/api/v1/settings', ['color_theme' => 'neon'])->assertUnprocessable();

        $this->makePremium($user);
        $this->patchJson('/api/v1/settings', ['color_theme' => 'ocean'])
            ->assertOk()->assertJsonPath('data.color_theme', 'ocean');
    }

    public function test_gamification_lists_achievements(): void
    {
        $user = $this->signIn();
        $task = $user->tasks()->create(['title' => 'One']);
        $this->postJson("/api/v1/tasks/{$task->id}/complete");

        $res = $this->getJson('/api/v1/gamification')->assertOk();
        $first = collect($res->json('data.achievements'))->firstWhere('key', 'first_task');
        $this->assertNotNull($first['unlocked_at']);
        // 10 XP for a medium task + 10 for the achievement: still below the 50 XP needed for level 2.
        $res->assertJsonPath('data.xp', 20)->assertJsonPath('data.level', 1)->assertJsonPath('data.level_progress.required', 50);
    }
}
