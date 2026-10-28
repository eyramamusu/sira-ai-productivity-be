<?php

namespace App\Ai;

use App\Models\AiUsage;
use App\Models\Goal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Premium AI coach. Every call checks the user's daily allowance first and only counts
 * against it once the provider has answered, so outages and missing keys cost nothing.
 */
class CoachService
{
    private const PERSONA = 'You are Sira, a warm, practical productivity coach inside a task, habit and focus app. '
        .'Give specific, actionable advice in plain language. Keep answers short (under 150 words) unless asked for more. '
        .'Use the user\'s own tasks, habits and goals when they are relevant, and never invent tasks they do not have.';

    public function usage(User $user): array
    {
        $limit = (int) config('sira.ai.daily_request_limit');
        $used = (int) AiUsage::where('user_id', $user->id)->where('date', $user->today())->value('requests');

        return ['used' => $used, 'limit' => $limit, 'remaining' => max(0, $limit - $used)];
    }

    public function chat(User $user, string $message): string
    {
        $history = $user->coachMessages()->latest('id')->limit(20)->get()->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->values()->all();

        $reply = $this->run($user, fn (CoachProvider $p) => $p->chat(
            self::PERSONA."\n\n".$this->context($user),
            [...$history, ['role' => 'user', 'content' => $message]],
        ));

        $user->coachMessages()->create(['role' => 'user', 'content' => $message]);
        $user->coachMessages()->create(['role' => 'assistant', 'content' => $reply]);

        return $reply;
    }

    public function breakdown(User $user, string $goal): array
    {
        $schema = $this->object([
            'summary' => ['type' => 'string'],
            'tasks' => ['type' => 'array', 'items' => $this->object([
                'title' => ['type' => 'string'],
                'priority' => ['type' => 'string', 'enum' => Task::PRIORITIES],
                'estimate_minutes' => ['type' => 'integer'],
                'due_in_days' => ['type' => 'integer'],
            ])],
        ]);
        $prompt = "Break this goal into 3 to 8 concrete tasks, in the order they should be done. due_in_days counts from today (0 = today).\n\nGoal: {$goal}";

        $data = $this->run($user, fn (CoachProvider $p) => $p->structured('breakdown', self::PERSONA, $prompt, $schema, ['goal' => $goal]));

        return [
            'summary' => (string) ($data['summary'] ?? ''),
            'tasks' => collect($data['tasks'] ?? [])->take(10)->map(fn ($t) => [
                'title' => mb_substr((string) ($t['title'] ?? ''), 0, 200),
                'priority' => in_array($t['priority'] ?? null, Task::PRIORITIES, true) ? $t['priority'] : 'medium',
                'estimate_minutes' => max(5, (int) ($t['estimate_minutes'] ?? 30)),
                'due_in_days' => max(0, (int) ($t['due_in_days'] ?? 0)),
            ])->filter(fn ($t) => $t['title'] !== '')->values()->all(),
        ];
    }

    public function breakdownGoal(User $user, Goal $goal): array
    {
        return $this->breakdown($user, trim($goal->title.($goal->description ? "\n".$goal->description : '')));
    }

    public function prioritize(User $user): array
    {
        $tasks = $this->openTasks($user, 50);
        if ($tasks === []) {
            return ['summary' => 'You have no open tasks to prioritise.', 'suggestions' => []];
        }

        $schema = $this->object([
            'summary' => ['type' => 'string'],
            'suggestions' => ['type' => 'array', 'items' => $this->object([
                'task_id' => ['type' => 'string'],
                'priority' => ['type' => 'string', 'enum' => Task::PRIORITIES],
                'reason' => ['type' => 'string'],
            ])],
        ]);
        $prompt = "Today is {$user->today()}. Order these open tasks from most to least important to work on next, "
            ."suggest a priority for each, and give a one-sentence reason. Use the task ids exactly as given.\n\n"
            .json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $data = $this->run($user, fn (CoachProvider $p) => $p->structured('prioritize', self::PERSONA, $prompt, $schema, ['tasks' => $tasks]));

        // Only ids from this user's list survive, whatever the model returns.
        $own = array_flip(array_column($tasks, 'id'));

        return [
            'summary' => (string) ($data['summary'] ?? ''),
            'suggestions' => collect($data['suggestions'] ?? [])
                ->filter(fn ($s) => isset($own[$s['task_id'] ?? '']))
                ->unique('task_id')
                ->map(fn ($s) => [
                    'task_id' => $s['task_id'],
                    'priority' => in_array($s['priority'] ?? null, Task::PRIORITIES, true) ? $s['priority'] : 'medium',
                    'reason' => (string) ($s['reason'] ?? ''),
                ])->values()->all(),
        ];
    }

    public function planDay(User $user, ?string $date): array
    {
        $date ??= $user->today();
        $tasks = collect($this->openTasks($user, 30))
            ->filter(fn ($t) => $t['due_date'] === null || $t['due_date'] <= $date)
            ->values()->all();
        $settings = $user->settingsOrDefault();

        $schema = $this->object([
            'summary' => ['type' => 'string'],
            'blocks' => ['type' => 'array', 'items' => $this->object([
                'start' => ['type' => 'string', 'description' => 'HH:MM, 24-hour'],
                'end' => ['type' => 'string', 'description' => 'HH:MM, 24-hour'],
                'title' => ['type' => 'string'],
                'task_id' => ['type' => ['string', 'null']],
                'kind' => ['type' => 'string', 'enum' => ['focus', 'break', 'meeting', 'admin']],
            ])],
        ]);
        $prompt = "Plan {$date} as time blocks between 08:00 and 18:00. Respect fixed start/end times, use "
            ."{$settings->focus_minutes}-minute focus blocks with {$settings->break_minutes}-minute breaks, and use task ids exactly as given "
            ."(null for breaks and admin).\n\nTasks:\n".json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $data = $this->run($user, fn (CoachProvider $p) => $p->structured('plan_day', self::PERSONA, $prompt, $schema, ['tasks' => $tasks, 'date' => $date]));
        $own = array_flip(array_column($tasks, 'id'));

        return [
            'date' => $date,
            'summary' => (string) ($data['summary'] ?? ''),
            'blocks' => collect($data['blocks'] ?? [])
                ->filter(fn ($b) => preg_match('/^\d{2}:\d{2}$/', $b['start'] ?? '') && preg_match('/^\d{2}:\d{2}$/', $b['end'] ?? ''))
                ->map(fn ($b) => [
                    'start' => $b['start'],
                    'end' => $b['end'],
                    'title' => (string) ($b['title'] ?? ''),
                    'task_id' => isset($own[$b['task_id'] ?? '']) ? $b['task_id'] : null,
                    'kind' => in_array($b['kind'] ?? null, ['focus', 'break', 'meeting', 'admin'], true) ? $b['kind'] : 'focus',
                ])->values()->all(),
        ];
    }

    /** Checks the allowance, calls the provider, and counts the request only if it succeeded. */
    private function run(User $user, callable $call): mixed
    {
        if ($this->usage($user)['remaining'] <= 0) {
            throw CoachUnavailableException::dailyLimit();
        }

        $provider = app(CoachProvider::class);
        $result = $call($provider);
        $tokens = $provider->lastUsage();

        DB::transaction(function () use ($user, $tokens) {
            $usage = AiUsage::firstOrCreate(['user_id' => $user->id, 'date' => $user->today()], ['requests' => 0]);
            $usage->incrementEach(['requests' => 1, 'input_tokens' => $tokens['input'], 'output_tokens' => $tokens['output']]);
        });

        return $result;
    }

    private function openTasks(User $user, int $limit): array
    {
        return $user->tasks()->open()
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('position')
            ->limit($limit)->get()
            ->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'priority' => $t->priority,
                'due_date' => $t->due_date?->toDateString(),
                'start_time' => $t->start_time,
                'end_time' => $t->end_time,
            ])->all();
    }

    private function context(User $user): string
    {
        $tasks = collect($this->openTasks($user, 15))
            ->map(fn ($t) => "- {$t['title']} ({$t['priority']}".($t['due_date'] ? ", due {$t['due_date']}" : '').')')->implode("\n");
        $habits = $user->habits()->active()->pluck('name')->implode(', ');
        $goals = $user->goals()->active()->get(['title', 'progress'])->map(fn ($g) => "{$g->title} ({$g->progress}%)")->implode(', ');
        $now = Carbon::parse($user->now())->format('l j F Y, H:i');

        return "It is {$now} for the user ({$user->name}).\nOpen tasks:\n".($tasks ?: '- none')
            ."\nHabits: ".($habits ?: 'none')."\nGoals: ".($goals ?: 'none');
    }

    private function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
