<?php

use App\Http\Middleware\EnsurePremium;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a TLS-terminating proxy (Render, load balancers) so generated URLs use https and
        // rate limits see the real client IP. TRUSTED_PROXIES is a comma-separated list, or '*'
        // for the single hop in front of the app. Set it as a real environment variable.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }
        $middleware->throttleApi();
        $middleware->alias(['premium' => EnsurePremium::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API is consumed by the app only, so always answer with JSON.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
