<?php

namespace App\Ai;

use Anthropic\Beta\Messages\BetaJSONOutputFormat;
use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaOutputConfig;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * Claude via the Messages API. Requests opt into the server-side refusal fallback,
 * so a policy decline on the main model is retried on a fallback model instead of failing.
 */
class AnthropicCoachProvider implements CoachProvider
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private array $usage = ['input' => 0, 'output' => 0];

    public function __construct(private Client $client, private string $model, private string $effort) {}

    public function lastUsage(): array
    {
        return $this->usage;
    }

    public function chat(string $system, array $messages): string
    {
        $message = $this->send($system, $messages, 4000);

        return $this->text($message);
    }

    public function structured(string $kind, string $system, string $prompt, array $schema, array $context = []): array
    {
        $message = $this->send($system, [['role' => 'user', 'content' => $prompt]], 8000, $schema);
        $data = json_decode($this->text($message), true);
        if (! is_array($data)) {
            throw CoachUnavailableException::unavailable('The AI coach returned an unexpected answer. Please try again.');
        }

        return $data;
    }

    private function send(string $system, array $messages, int $maxTokens, ?array $schema = null): BetaMessage
    {
        try {
            $message = $this->client->beta->messages->create(
                maxTokens: $maxTokens,
                messages: $messages,
                model: $this->model,
                system: $system,
                outputConfig: BetaOutputConfig::with(
                    effort: $this->effort,
                    format: $schema ? BetaJSONOutputFormat::with(schema: $schema) : null,
                ),
                fallbacks: 'default',
                betas: [self::FALLBACK_BETA],
            );
        } catch (AuthenticationException $e) {
            report($e);
            throw CoachUnavailableException::notConfigured();
        } catch (RateLimitException) {
            throw CoachUnavailableException::unavailable('The AI coach is busy right now. Please try again in a minute.');
        } catch (BadRequestException $e) {
            report($e);
            throw CoachUnavailableException::unavailable();
        } catch (APIStatusException|APIConnectionException $e) {
            report($e);
            throw CoachUnavailableException::unavailable();
        }

        $this->usage = ['input' => (int) $message->usage->inputTokens, 'output' => (int) $message->usage->outputTokens];

        if ($message->stopReason === 'refusal') {
            throw new CoachUnavailableException("The coach can't help with that request.", 422, 'ai_refused');
        }
        if ($message->stopReason === 'max_tokens') {
            throw CoachUnavailableException::unavailable('The AI coach answer was too long. Please try a narrower question.');
        }

        return $message;
    }

    private function text(BetaMessage $message): string
    {
        $out = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $out .= $block->text;
            }
        }

        return trim($out);
    }
}
