<?php

namespace App\Ai;

use RuntimeException;

/** Deterministic answers for local development and tests. */
class FakeCoachProvider implements CoachProvider
{
    public function __construct()
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The fake AI driver only runs in local or testing environments.');
        }
    }

    public function chat(string $system, array $messages): string
    {
        $last = end($messages)['content'] ?? '';

        return "Here's a thought on \"".mb_strimwidth($last, 0, 60, '…').'": pick the one task that moves your biggest goal forward and give it a 25-minute focus session first.';
    }

    public function structured(string $kind, string $system, string $prompt, array $schema, array $context = []): array
    {
        return match ($kind) {
            'breakdown' => [
                'summary' => 'Three steps to get "'.($context['goal'] ?? 'your goal').'" moving.',
                'tasks' => [
                    ['title' => 'Define what done looks like', 'priority' => 'high', 'estimate_minutes' => 20, 'due_in_days' => 0],
                    ['title' => 'Do the first small step', 'priority' => 'medium', 'estimate_minutes' => 45, 'due_in_days' => 1],
                    ['title' => 'Review progress and plan the next step', 'priority' => 'low', 'estimate_minutes' => 15, 'due_in_days' => 3],
                ],
            ],
            'prioritize' => [
                'summary' => 'Start with what is due soonest and matters most.',
                'suggestions' => collect($context['tasks'] ?? [])->map(fn ($t) => [
                    'task_id' => $t['id'],
                    'priority' => $t['priority'],
                    'reason' => $t['due_date'] ? "Due {$t['due_date']}." : 'No due date yet.',
                ])->values()->all(),
            ],
            'plan_day' => [
                'summary' => 'A focused day with breaks between blocks.',
                'blocks' => collect($context['tasks'] ?? [])->take(4)->values()->map(fn ($t, $i) => [
                    'start' => sprintf('%02d:00', 9 + $i * 2),
                    'end' => sprintf('%02d:30', 10 + $i * 2),
                    'title' => $t['title'],
                    'task_id' => $t['id'],
                    'kind' => 'focus',
                ])->all(),
            ],
            default => [],
        };
    }

    public function lastUsage(): array
    {
        return ['input' => 10, 'output' => 20];
    }
}
