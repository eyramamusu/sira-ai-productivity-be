<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
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

    public function test_reset_email_links_to_hosted_page_that_resets_the_password(): void
    {
        Notification::fake();
        config(['sira.password_reset_url' => 'https://api.sira.test/reset-password']);
        $user = User::factory()->create(['email' => 'kofi@example.com']);
        $user->createToken('phone');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'kofi@example.com'])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            return str_starts_with($n->toMail($user)->actionUrl, 'https://api.sira.test/reset-password?token=');
        });

        $token = Password::createToken($user);
        $this->get('/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]))
            ->assertOk()->assertSee('Choose a new password')->assertSee($token);

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/reset-password', ['token' => 'wrong', 'email' => $user->email, 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertRedirect()->assertSessionHasErrors('email');

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertOk()->assertSee('Password updated');

        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['email' => 'kofi@example.com', 'password' => 'newpass123'])->assertOk();
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

    public function test_avatar_upload_replace_and_remove(): void
    {
        Storage::fake('public');
        $user = $this->signIn();

        $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('avatar');

        $this->postJson('/api/v1/me/avatar', ['avatar' => $this->png('me.png')])
            ->assertOk()->assertJsonPath('data.avatar_url', fn ($url) => str_contains($url, '/storage/avatars/'));
        $first = $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($first);

        $this->postJson('/api/v1/me/avatar', ['avatar' => $this->png('me2.png')])->assertOk();
        Storage::disk('public')->assertMissing($first);
        $second = $user->fresh()->avatar_path;

        $this->deleteJson('/api/v1/me/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_account_deletion_removes_avatar_file(): void
    {
        Storage::fake('public');
        $user = $this->signIn(User::factory()->create(['password' => 'secret123']));
        $this->postJson('/api/v1/me/avatar', ['avatar' => $this->png('me.png')])->assertOk();
        $path = $user->fresh()->avatar_path;

        $this->deleteJson('/api/v1/me', ['password' => 'secret123'])->assertOk();
        Storage::disk('public')->assertMissing($path);
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

    /** A real 1x1 PNG, so the image rules run without the GD extension. */
    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    }
}
