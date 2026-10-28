<?php

namespace App\Ai;

use App\Exceptions\ApiException;

class CoachUnavailableException extends ApiException
{
    public static function notConfigured(): self
    {
        return new self('The AI coach is not configured on the server (missing ANTHROPIC_API_KEY).', 503, 'ai_not_configured');
    }

    public static function dailyLimit(): self
    {
        return new self("You've reached today's AI coach limit. It resets tomorrow.", 429, 'ai_daily_limit_reached');
    }

    public static function unavailable(string $message = 'The AI coach is unavailable right now. Please try again shortly.'): self
    {
        return new self($message, 503, 'ai_unavailable');
    }
}
