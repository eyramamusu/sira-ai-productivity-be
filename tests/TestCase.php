<?php

namespace Tests;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /** Key generation needs an openssl.cnf; Windows PHP builds often don't ship one on the default path. */
    protected function opensslConfig(): array
    {
        foreach ([getenv('OPENSSL_CONF'), 'C:/xampp/php/extras/ssl/openssl.cnf', 'C:/xampp/apache/conf/openssl.cnf'] as $path) {
            if ($path && is_readable($path)) {
                return ['config' => $path];
            }
        }

        return [];
    }

    protected function makePremium(User $user): Subscription
    {
        return $user->subscriptions()->create([
            'platform' => 'manual',
            'product_id' => 'sira_premium_monthly',
            'plan' => 'monthly',
            'store_transaction_id' => 'manual-'.$user->id,
            'store_transaction_hash' => Subscription::hashToken('manual', 'manual-'.$user->id),
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);
    }
}
