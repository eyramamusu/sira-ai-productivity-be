<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_task_with_subtasks_and_category(): void
    {
        $user = $this->signIn();
        $category = $user->categories()->create(['name' => 'Work']);

        $res = $this->postJson('/api/v1/tasks', [
            'title' => 'Finish project proposal',
            'description' => 'Final draft',
            'priority' => 'high',
            'category_id' => $category->id,
            'due_date' => now()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '12:00',
            'subtasks' => [['title' => 'Research'], ['title' => 'Write draft', 'completed' => true]],
        ])->assertCreated();

        $res->assertJsonPath('data.category.name', 'Work')
            ->assertJsonCount(2, 'data.subtasks')
            ->assertJsonPath('data.subtasks.1.completed', true);
    }

    public function test_default_priority_comes_from_settings(): void
    {
        $user = $this->signIn();
        $user->settingsOrDefault()->update(['default_priority' => 'low']);

        $this->postJson('/api/v1/tasks', ['title' => 'Default'])->assertCreated()->assertJsonPath('data.priority', 'low');
    }

    public function test_cannot_use_another_users_category_or_task(): void
    {
        $other = User::factory()->create();
        $foreignCategory = $other->categories()->create(['name' => 'Theirs']);
        $foreignTask = $other->tasks()->create(['title' => 'Private']);
        $this->signIn();

        $this->postJson('/api/v1/tasks', ['title' => 'x', 'category_id' => $foreignCategory->id])
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->getJson("/api/v1/tasks/{$foreignTask->id}")->assertForbidden();
        $this->patchJson("/api/v1/tasks/{$foreignTask->id}", ['title' => 'hacked'])->assertForbidden();
        $this->deleteJson("/api/v1/tasks/{$foreignTask->id}")->assertForbidden();
        $this->assertSame('Private', $foreignTask->fresh()->title);
    }

    public function test_index_filters_and_paginates(): void
    {
        $user = $this->signIn();
        $today = now()->toDateString();
        $user->tasks()->create(['title' => 'Today', 'due_date' => $today]);
        $user->tasks()->create(['title' => 'Overdue', 'due_date' => now()->subDays(2)->toDateString()]);
        $user->tasks()->create(['title' => 'Later', 'due_date' => now()->addDays(3)->toDateString()]);
        $done = $user->tasks()->create(['title' => 'Done', 'due_date' => $today]);
        $done->forceFill(['completed_at' => now()])->save();
        User::factory()->create()->tasks()->create(['title' => 'Not mine', 'due_date' => $today]);

        $this->getJson('/api/v1/tasks?view=today')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/tasks?view=upcoming')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Later');
        $this->getJson('/api/v1/tasks?view=completed')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/tasks?search=Lat')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/tasks?per_page=2')->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4);
    }

    public function test_completing_awards_xp_and_reopening_revokes_it(): void
    {
        $user = $this->signIn();
        $task = $user->tasks()->create(['title' => 'Workout', 'priority' => 'high', 'due_date' => now()->toDateString()]);

        $this->postJson("/api/v1/tasks/{$task->id}/complete")->assertOk()
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('achievements_unlocked.0', 'first_task');

        // 15 XP for a high-priority task + 10 for the "first_task" achievement.
        $this->assertSame(25, $user->fresh()->xp);

        // Completing twice doesn't double-award.
        $this->postJson("/api/v1/tasks/{$task->id}/complete")->assertOk();
        $this->assertSame(25, $user->fresh()->xp);

        $this->postJson("/api/v1/tasks/{$task->id}/reopen")->assertOk()->assertJsonPath('data.completed', false);
        $this->assertSame(10, $user->fresh()->xp);
    }

    public function test_completing_a_recurring_task_creates_the_next_occurrence(): void
    {
        $user = $this->signIn();
        $task = $user->tasks()->create(['title' => 'Read 10 pages', 'due_date' => '2026-05-12', 'repeat_rule' => 'daily']);
        $task->subtasks()->create(['title' => 'Pick a book', 'completed' => true]);

        $this->postJson("/api/v1/tasks/{$task->id}/complete")->assertOk();
        $this->postJson("/api/v1/tasks/{$task->id}/reopen");
        $this->postJson("/api/v1/tasks/{$task->id}/complete");

        $next = Task::where('recurrence_parent_id', $task->id)->get();
        $this->assertCount(1, $next);
        $this->assertSame('2026-05-13', $next[0]->due_date->toDateString());
        $this->assertNull($next[0]->completed_at);
        $this->assertFalse($next[0]->subtasks()->first()->completed);
    }

    public function test_offline_create_replay_is_idempotent_and_sync_reports_deletions(): void
    {
        $this->signIn();
        $id = (string) Str::uuid();

        $this->postJson('/api/v1/tasks', ['id' => $id, 'title' => 'Offline'])->assertCreated();
        $this->postJson('/api/v1/tasks', ['id' => $id, 'title' => 'Offline edited'])->assertOk();
        $this->assertSame(1, Task::count());

        $serverTime = $this->getJson('/api/v1/sync')->assertOk()->assertJsonPath('full', true)->json('server_time');
        $this->travel(5)->seconds();
        $this->deleteJson("/api/v1/tasks/{$id}")->assertNoContent();

        $delta = $this->getJson('/api/v1/sync?since='.urlencode($serverTime))->assertOk();
        $delta->assertJsonPath('tasks.0.id', $id);
        $this->assertNotNull($delta->json('tasks.0.deleted_at'));
    }

    public function test_replaying_another_users_id_is_rejected(): void
    {
        $theirs = User::factory()->create()->tasks()->create(['title' => 'Theirs']);
        $this->signIn();

        $this->postJson('/api/v1/tasks', ['id' => $theirs->id, 'title' => 'Mine now'])->assertStatus(409);
        $this->assertSame('Theirs', $theirs->fresh()->title);
    }

    public function test_subtask_endpoints(): void
    {
        $user = $this->signIn();
        $task = $user->tasks()->create(['title' => 'Parent']);

        $subId = $this->postJson("/api/v1/tasks/{$task->id}/subtasks", ['title' => 'Child'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/tasks/{$task->id}/subtasks/{$subId}", ['completed' => true])->assertOk()->assertJsonPath('data.completed', true);
        $this->assertSame(2, $user->fresh()->xp);
        $this->deleteJson("/api/v1/tasks/{$task->id}/subtasks/{$subId}")->assertNoContent();
    }

    public function test_overview_and_calendar(): void
    {
        $user = $this->signIn();
        $today = now()->toDateString();
        $user->tasks()->create(['title' => 'A', 'due_date' => $today]);
        $b = $user->tasks()->create(['title' => 'B', 'due_date' => $today]);
        $this->postJson("/api/v1/tasks/{$b->id}/complete");

        $this->getJson('/api/v1/overview')->assertOk()
            ->assertJsonPath('data.progress.completed', 1)
            ->assertJsonPath('data.progress.total', 2)
            ->assertJsonPath('data.progress.percent', 50)
            ->assertJsonPath('data.gamification.day_streak', 1);

        $this->getJson('/api/v1/calendar?from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString())
            ->assertOk()->assertJsonCount(2, 'data.tasks');
    }
}
