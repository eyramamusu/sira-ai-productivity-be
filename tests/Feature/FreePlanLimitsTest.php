<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class FreePlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_plan_adds_five_tasks_a_day(): void
    {
        Carbon::setTestNow('2026-05-12 10:00:00');
        $user = $this->signIn();
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/tasks', ['title' => "Task {$i}"])->assertCreated();
        }

        $this->postJson('/api/v1/tasks', ['title' => 'Sixth'])->assertForbidden()->assertJsonPath('code', 'plan_limit_reached');

        Carbon::setTestNow('2026-05-13 10:00:00');
        $this->postJson('/api/v1/tasks', ['title' => 'Tomorrow'])->assertCreated();

        $this->makePremium($user);
        foreach (range(1, 6) as $i) {
            $this->postJson('/api/v1/tasks', ['title' => "Premium {$i}"])->assertCreated();
        }
    }

    public function test_replaying_a_queued_task_does_not_count_twice(): void
    {
        $this->signIn();
        $id = (string) Str::uuid();
        $this->postJson('/api/v1/tasks', ['id' => $id, 'title' => 'Once'])->assertCreated();
        foreach (range(1, 4) as $i) {
            $this->postJson('/api/v1/tasks', ['title' => "Task {$i}"])->assertCreated();
        }

        $this->postJson('/api/v1/tasks', ['id' => $id, 'title' => 'Once'])->assertOk();
    }

    public function test_repeat_occurrences_do_not_use_the_daily_allowance(): void
    {
        $this->signIn();
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $this->postJson('/api/v1/tasks', ['title' => "Daily {$i}", 'due_date' => now()->toDateString(), 'repeat_rule' => 'daily'])->json('data.id');
        }
        $this->postJson("/api/v1/tasks/{$ids[0]}/complete")->assertOk();

        $this->assertSame(6, Task::count());
    }

    public function test_free_tasks_hold_two_subtasks(): void
    {
        $user = $this->signIn();
        $sub = fn (int $n) => array_map(fn ($i) => ['title' => "Step {$i}"], range(1, $n));

        $this->postJson('/api/v1/tasks', ['title' => 'Too many', 'subtasks' => $sub(3)])->assertForbidden()->assertJsonPath('code', 'plan_limit_reached');
        $id = $this->postJson('/api/v1/tasks', ['title' => 'Two', 'subtasks' => $sub(2)])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/tasks/{$id}", ['subtasks' => $sub(3)])->assertForbidden();
        $this->postJson("/api/v1/tasks/{$id}/subtasks", ['title' => 'Third'])->assertForbidden()->assertJsonPath('code', 'plan_limit_reached');

        $sub = $this->makePremium($user);
        $this->postJson("/api/v1/tasks/{$id}/subtasks", ['title' => 'Third'])->assertCreated();
        $this->postJson("/api/v1/tasks/{$id}/subtasks", ['title' => 'Fourth'])->assertCreated();

        // After Premium lapses, the longer list stays and can shrink, but not grow.
        $sub->update(['expires_at' => now()->subDay()]);
        $this->patchJson("/api/v1/tasks/{$id}", ['title' => 'Renamed'])->assertOk()->assertJsonCount(4, 'data.subtasks');
        $this->patchJson("/api/v1/tasks/{$id}", ['subtasks' => [['title' => 'A'], ['title' => 'B'], ['title' => 'C']]])->assertOk();
        $this->postJson("/api/v1/tasks/{$id}/subtasks", ['title' => 'D'])->assertForbidden();
    }

    public function test_free_focus_sessions_are_25_minutes(): void
    {
        $user = $this->signIn();
        $session = fn (int $minutes, string $type = 'focus') => [
            'type' => $type,
            'started_at' => now()->subMinutes($minutes)->toIso8601String(),
            'ended_at' => now()->toIso8601String(),
            'planned_seconds' => $minutes * 60,
            'duration_seconds' => $minutes * 60,
            'completed' => true,
        ];

        $this->postJson('/api/v1/focus-sessions', $session(25))->assertCreated();
        $this->postJson('/api/v1/focus-sessions', $session(60))->assertForbidden()->assertJsonPath('code', 'plan_limit_reached');
        $this->postJson('/api/v1/focus-sessions', $session(30, 'break'))->assertCreated();
        $this->patchJson('/api/v1/settings', ['focus_minutes' => 60])->assertForbidden();
        $this->patchJson('/api/v1/settings', ['focus_minutes' => 25])->assertOk();

        $this->makePremium($user);
        $this->postJson('/api/v1/focus-sessions', $session(60))->assertCreated();
        $this->patchJson('/api/v1/settings', ['focus_minutes' => 45])->assertOk()->assertJsonPath('data.focus_minutes', 45);
    }

    public function test_entitlements_list_the_new_limits(): void
    {
        $user = $this->signIn();
        $this->getJson('/api/v1/subscription')->assertOk()
            ->assertJsonPath('data.limits.max_tasks_per_day', 5)
            ->assertJsonPath('data.limits.max_subtasks_per_task', 2)
            ->assertJsonPath('data.limits.focus_minutes', 25)
            ->assertJsonPath('data.features.custom_focus', false);

        $this->makePremium($user);
        $this->getJson('/api/v1/subscription')->assertOk()
            ->assertJsonPath('data.limits.max_tasks_per_day', null)
            ->assertJsonPath('data.limits.focus_minutes', null);
    }
}
