<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePremium
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isPremium()) {
            throw ApiException::premiumRequired();
        }

        return $next($request);
    }
}
