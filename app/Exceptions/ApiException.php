<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** An error the app can act on: rendered as `{message, code}` with its HTTP status. */
class ApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function premiumRequired(string $message = 'This feature needs Sira Premium.'): static
    {
        return new static($message, 403, 'premium_required');
    }

    public static function planLimit(string $message): static
    {
        return new static($message, 403, 'plan_limit_reached');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }

    /** Client errors are expected and not logged; server-side problems (5xx) are. */
    public function report(): bool
    {
        return $this->status < 500;
    }
}
