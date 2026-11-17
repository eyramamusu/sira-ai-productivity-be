<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CronEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_is_disabled_without_a_secret(): void
    {
        config(['sira.cron_secret' => '']);

        $this->postJson('/api/v1/cron/run')->assertNotFound();
        $this->postJson('/api/v1/cron/run', [], ['X-Cron-Secret' => ''])->assertNotFound();
    }

    public function test_wrong_secret_is_rejected(): void
    {
        config(['sira.cron_secret' => 'right-secret']);

        $this->postJson('/api/v1/cron/run')->assertForbidden();
        $this->postJson('/api/v1/cron/run', [], ['X-Cron-Secret' => 'wrong'])->assertForbidden();
    }

    public function test_correct_secret_runs_the_scheduler(): void
    {
        config(['sira.cron_secret' => 'right-secret']);

        $this->getJson('/api/v1/cron/run', ['X-Cron-Secret' => 'right-secret'])
            ->assertOk()->assertJsonPath('ran', true);
    }
}
