<?php

namespace App\Providers;

use Anthropic\Client;
use App\Ai\AnthropicCoachProvider;
use App\Ai\CoachProvider;
use App\Ai\CoachUnavailableException;
use App\Ai\FakeCoachProvider;
use App\Billing\FakeVerifier;
use App\Billing\PurchaseVerifier;
use App\Billing\StoreVerifier;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Resolved per use (not singletons) so drivers follow the current config.
        $this->app->bind(PurchaseVerifier::class, fn ($app) => match (config('sira.billing.driver')) {
            'fake' => $app->make(FakeVerifier::class),
            default => $app->make(StoreVerifier::class), // `revenuecat` and `store`: routed by the purchase's platform
        });

        $this->app->bind(CoachProvider::class, function ($app) {
            if (config('sira.ai.driver') === 'fake') {
                return $app->make(FakeCoachProvider::class);
            }

            $config = config('sira.ai.anthropic');
            if (empty($config['api_key'])) {
                throw CoachUnavailableException::notConfigured();
            }

            return new AnthropicCoachProvider(new Client(apiKey: $config['api_key']), $config['model'], $config['effort']);
        });
    }

    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('coach', fn (Request $request) => Limit::perMinute(10)->by('coach:'.($request->user()?->id ?: $request->ip())));

        // Reset links open the app's reset screen.
        ResetPassword::createUrlUsing(fn ($user, string $token) => config('sira.password_reset_url').'?'.http_build_query([
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));
    }
}
