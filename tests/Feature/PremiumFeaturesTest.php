<?php

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PremiumFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_basic_analytics_is_free_but_long_ranges_are_premium(): void
    {
        $user = $this->signIn();
        $task = $user->tasks()->create(['title' => 'Done', 'category_id' => $user->categories()->create(['name' => 'Work'])->id]);
        $this->postJson("/api/v1/tasks/{$task->id}/complete");

        $this->getJson('/api/v1/analytics')->assertOk()
            ->assertJsonCount(7, 'data.days')
            ->assertJsonPath('data.totals.tasks_completed', 1)
            ->assertJsonPath('data.categories.0.name', 'Work')
            ->assertJsonPath('data.categories.0.percent', 100);

        $from = now()->subDays(29)->toDateString();
        $this->getJson("/api/v1/analytics?from={$from}")->assertForbidden()->assertJsonPath('code', 'premium_required');

        $this->makePremium($user);
        $this->getJson("/api/v1/analytics?from={$from}")->assertOk()->assertJsonCount(30, 'data.days');
    }

    public function test_advanced_analytics_and_reports_require_premium(): void
    {
        $user = $this->signIn();
        $this->getJson('/api/v1/analytics/advanced')->assertForbidden()->assertJsonPath('code', 'premium_required');
        $this->getJson('/api/v1/reports/productivity')->assertForbidden();

        $this->makePremium($user);
        $this->getJson('/api/v1/analytics/advanced')->assertOk()->assertJsonStructure(['data' => ['completion_rate', 'by_hour', 'habits']]);
        $this->getJson('/api/v1/reports/productivity')->assertOk()->assertJsonStructure(['data' => ['summary', 'advanced', 'highlights']]);
        $csv = $this->get('/api/v1/reports/productivity?format=csv')->assertOk();
        $this->assertStringStartsWith('date,tasks_completed,focus_minutes', $csv->streamedContent());
    }

    public function test_coach_requires_premium(): void
    {
        $this->signIn();
        $this->postJson('/api/v1/coach/chat', ['message' => 'Help'])->assertForbidden()->assertJsonPath('code', 'premium_required');
    }

    public function test_coach_chat_breakdown_prioritize_and_plan(): void
    {
        $user = $this->signIn();
        $this->makePremium($user);
        $mine = $user->tasks()->create(['title' => 'Mine', 'due_date' => now()->toDateString()]);

        $this->postJson('/api/v1/coach/chat', ['message' => 'What should I do first?'])->assertOk()
            ->assertJsonStructure(['data' => ['reply'], 'usage' => ['used', 'limit', 'remaining']])
            ->assertJsonPath('usage.used', 1);
        $this->getJson('/api/v1/coach/messages')->assertOk()->assertJsonCount(2, 'data');

        $this->postJson('/api/v1/coach/breakdown', ['goal' => 'Run a 10k'])->assertOk()->assertJsonCount(3, 'data.tasks');
        $this->postJson('/api/v1/coach/prioritize')->assertOk()->assertJsonPath('data.suggestions.0.task_id', $mine->id);
        $this->postJson('/api/v1/coach/plan-day')->assertOk()->assertJsonStructure(['data' => ['blocks', 'summary', 'date']]);
    }

    public function test_coach_enforces_daily_limit(): void
    {
        config(['sira.ai.daily_request_limit' => 2]);
        $user = $this->signIn();
        $this->makePremium($user);

        $this->postJson('/api/v1/coach/chat', ['message' => 'one'])->assertOk();
        $this->postJson('/api/v1/coach/chat', ['message' => 'two'])->assertOk();
        $this->postJson('/api/v1/coach/chat', ['message' => 'three'])->assertStatus(429)->assertJsonPath('code', 'ai_daily_limit_reached');
        $this->assertSame(2, AiUsage::where('user_id', $user->id)->value('requests'));
    }

    public function test_coach_reports_missing_provider_key(): void
    {
        config(['sira.ai.driver' => 'anthropic', 'sira.ai.anthropic.api_key' => null]);
        $user = $this->signIn();
        $this->makePremium($user);

        $this->postJson('/api/v1/coach/chat', ['message' => 'hi'])->assertStatus(503)->assertJsonPath('code', 'ai_not_configured');
        // A failed provider call doesn't consume the daily allowance.
        $this->assertSame(0, (int) AiUsage::where('user_id', $user->id)->value('requests'));
    }

    public function test_prioritize_ignores_task_ids_the_user_does_not_own(): void
    {
        $user = $this->signIn();
        $this->makePremium($user);
        User::factory()->create()->tasks()->create(['title' => 'Not mine']);

        $this->postJson('/api/v1/coach/prioritize')->assertOk()->assertJsonPath('data.suggestions', []);
    }
}
