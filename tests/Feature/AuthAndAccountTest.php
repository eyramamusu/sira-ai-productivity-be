<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthAndAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_token_and_seeds_defaults(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ama', 'email' => 'Ama@Example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'timezone' => 'Africa/Accra', 'device_name' => 'Pixel',
        ])->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'email', 'level', 'is_premium']]);

        $user = User::firstWhere('email', 'ama@example.com');
        $this->assertNotNull($user);
        $this->assertSame(5, $user->categories()->count());
        $this->assertNotNull($user->settings);

        $this->withToken($res->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'ama@example.com');
    }

    public function test_register_validates_password_and_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $this->postJson('/api/v1/auth/register', ['name' => 'X', 'email' => 'taken@example.com', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_and_logout(): void
    {
        User::factory()->create(['email' => 'kofi@example.com', 'password' => 'secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'kofi@example.com', 'password' => 'wrong'])->assertUnprocessable();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'KOFI@example.com', 'password' => 'secret123'])->assertOk()->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_guests_cannot_access_protected_routes(): void
    {
        $this->getJson('/api/v1/tasks')->assertUnauthorized();
    }

    public function test_password_reset_flow(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'efua@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'efua@example.com'])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::createToken($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'efua@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'efua@example.com', 'password' => 'newpass123'])->assertOk();
    }

    public function test_profile_update_and_password_change(): void
    {
        $user = $this->signIn(User::factory()->create(['password' => 'secret123']));

        $this->patchJson('/api/v1/me', ['name' => 'New Name', 'timezone' => 'Europe/London', 'onboarding_completed' => true])
            ->assertOk()->assertJsonPath('data.name', 'New Name')->assertJsonPath('data.onboarding_completed', true);

        $this->putJson('/api/v1/me/password', ['current_password' => 'nope', 'password' => 'another123', 'password_confirmation' => 'another123'])
            ->assertUnprocessable();
    }

    public function test_export_contains_user_data(): void
    {
        $user = $this->signIn();
        $user->tasks()->create(['title' => 'Exported task']);

        $this->getJson('/api/v1/me/export')->assertOk()
            ->assertJsonPath('profile.email', $user->email)
            ->assertJsonPath('tasks.0.title', 'Exported task');
    }

    public function test_account_deletion_removes_all_data(): void
    {
        $user = $this->signIn(User::factory()->create(['password' => 'secret123']));
        $user->tasks()->create(['title' => 'Gone soon']);
        Password::createToken($user);

        $this->deleteJson('/api/v1/me', ['password' => 'wrong'])->assertUnprocessable();
        $this->deleteJson('/api/v1/me', ['password' => 'secret123'])->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(0, Task::withTrashed()->count());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }
}
