<?php

namespace App\Ai;

/** The model behind the coach. Bind a different implementation in AppServiceProvider to switch vendors. */
interface CoachProvider
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     *
     * @throws CoachUnavailableException
     */
    public function chat(string $system, array $messages): string;

    /**
     * Returns JSON matching $schema. $context carries the structured inputs already
     * described in $prompt (the fake provider uses them; real models read the prompt).
     *
     * @throws CoachUnavailableException
     */
    public function structured(string $kind, string $system, string $prompt, array $schema, array $context = []): array;

    /** @return array{input: int, output: int} tokens used by the last call */
    public function lastUsage(): array;
}
