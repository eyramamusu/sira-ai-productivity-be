<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SecurityAlert;
use App\Notifications\WeeklyReview;
use App\Push\PushGateway;
use App\Push\PushMessage;
use App\Push\PushResult;
use App\Push\PushSender;
use App\Services\WeeklyReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{device: DeviceToken, message: PushMessage}> */
    private array $pushed = [];

    private PushResult $pushResult = PushResult::Delivered;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PushSender::class, new class($this) implements PushSender
        {
            public function __construct(private NotificationsTest $test) {}

            public function send(DeviceToken $device, PushMessage $message): PushResult
            {
                return $this->test->recordPush($device, $message);
            }
        });
    }

    public function recordPush(DeviceToken $device, PushMessage $message): PushResult
    {
        $this->pushed[] = ['device' => $device, 'message' => $message];

        return $this->pushResult;
    }

    public function test_devices_register_move_between_accounts_and_unregister(): void
    {
        $ama = $this->signIn();
        $this->postJson('/api/v1/devices', ['token' => 'tok-1', 'platform' => 'android'])->assertCreated();
        $this->postJson('/api/v1/devices', ['token' => 'tok-1', 'platform' => 'android'])->assertOk();
        $this->postJson('/api/v1/devices', ['token' => 'tok-2', 'platform' => 'windows'])->assertUnprocessable();
        $this->assertSame(1, $ama->deviceTokens()->count());

        // The same phone signs in to another account: the token follows the new account.
        $kofi = $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/devices', ['token' => 'tok-1', 'platform' => 'android'])->assertOk();
        $this->assertSame(0, $ama->deviceTokens()->count());

        $this->deleteJson('/api/v1/devices', ['token' => 'tok-1'])->assertOk();
        $this->assertSame(0, $kofi->deviceTokens()->count());
    }

    public function test_signing_out_removes_that_sessions_device(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Android')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/devices', ['token' => 'tok-1', 'platform' => 'android'])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseMissing('device_tokens', ['token' => 'tok-1']);
    }

    public function test_new_sign_in_alerts_other_devices_and_lands_in_the_inbox(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $user->deviceTokens()->create(['platform' => 'ios', 'token' => 'phone-1']);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret123', 'device_name' => 'Android'])->assertOk();

        $this->assertCount(1, $this->pushed);
        $this->assertSame('new_sign_in', $this->pushed[0]['message']->kind);
        $this->assertStringContainsString('Android', $this->pushed[0]['message']->body);
        $this->assertSame('/profile', $this->pushed[0]['message']->route);

        $this->signIn($user);
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.kind', 'new_sign_in')
            ->assertJsonPath('data.0.route', '/profile');
    }

    public function test_push_respects_settings_and_drops_dead_tokens(): void
    {
        $user = $this->signIn();
        $user->deviceTokens()->create(['platform' => 'android', 'token' => 'dead']);

        $this->patchJson('/api/v1/settings', ['push_enabled' => false])->assertOk()->assertJsonPath('data.push_enabled', false);
        $user->notify(new SecurityAlert(SecurityAlert::PASSWORD_CHANGED));
        $this->assertCount(0, $this->pushed);
        $this->assertSame(1, $user->notifications()->count(), 'still recorded in the inbox');

        $this->patchJson('/api/v1/settings', ['push_enabled' => true]);
        $this->pushResult = PushResult::InvalidToken;
        $user->notify(new SecurityAlert(SecurityAlert::PASSWORD_CHANGED));
        $this->assertCount(1, $this->pushed);
        $this->assertDatabaseMissing('device_tokens', ['token' => 'dead']);
    }

    public function test_password_change_sends_an_alert(): void
    {
        Notification::fake();
        $user = $this->signIn(User::factory()->create(['password' => 'secret123']));

        $this->putJson('/api/v1/me/password', ['current_password' => 'secret123', 'password' => 'another123', 'password_confirmation' => 'another123'])->assertOk();

        Notification::assertSentTo($user, SecurityAlert::class, fn (SecurityAlert $n) => $n->event === SecurityAlert::PASSWORD_CHANGED);
    }

    public function test_inbox_marks_read_and_deletes_only_own_notifications(): void
    {
        $other = User::factory()->create();
        $other->notify(new SecurityAlert(SecurityAlert::PASSWORD_CHANGED));
        $user = $this->signIn();
        $user->notify(new SecurityAlert(SecurityAlert::PASSWORD_CHANGED));
        $user->notify(new SecurityAlert(SecurityAlert::NEW_SIGN_IN, 'iOS'));
        [$newest, $oldest] = $user->notifications()->latest()->get()->all();
        $foreign = $other->notifications()->first();

        $this->postJson('/api/v1/notifications/read', ['ids' => [$newest->id, $foreign->id]])->assertOk()->assertJsonPath('meta.unread_count', 1);
        $this->assertNull($foreign->fresh()->read_at);

        $this->postJson('/api/v1/notifications/read')->assertJsonPath('meta.unread_count', 0);

        $this->deleteJson("/api/v1/notifications/{$foreign->id}")->assertOk();
        $this->assertNotNull($foreign->fresh());
        $this->deleteJson("/api/v1/notifications/{$oldest->id}")->assertOk();
        $this->getJson('/api/v1/notifications')->assertJsonCount(1, 'data');
    }

    public function test_achievements_go_to_the_inbox_without_a_push(): void
    {
        $user = $this->signIn();
        $user->deviceTokens()->create(['platform' => 'android', 'token' => 'phone-1']);
        $task = $user->tasks()->create(['title' => 'First']);

        $this->postJson("/api/v1/tasks/{$task->id}/complete")->assertOk();

        $this->assertCount(0, $this->pushed);
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.0.kind', 'achievement_unlocked')
            ->assertJsonPath('data.0.title', 'Achievement unlocked: First Step');
    }

    public function test_subscription_changes_notify_the_user(): void
    {
        $user = $this->signIn();
        $user->deviceTokens()->create(['platform' => 'android', 'token' => 'phone-1']);

        $this->postJson('/api/v1/subscription/verify', ['platform' => 'google_play', 'product_id' => 'sira_premium_monthly', 'purchase_token' => 'test-abc'])->assertOk();
        $this->assertSame(['subscription_activated'], $user->notifications()->pluck('type')->all());
        $this->assertCount(0, $this->pushed, 'the buying device already shows a confirmation');

        // The store now reports the plan as lapsed; the hourly re-check picks that up.
        Subscription::first()->update(['store_transaction_id' => 'test-expired', 'expires_at' => now()->addHour()]);
        $this->artisan('sira:subscriptions:refresh')->assertSuccessful();

        $this->assertContains('subscription_expired', $user->notifications()->pluck('type')->all());
        $this->assertSame('subscription_expired', $this->pushed[0]['message']->kind);
        $this->assertSame('/premium', $this->pushed[0]['message']->route);
    }

    public function test_weekly_review_goes_out_on_monday_morning_local_time_once(): void
    {
        Notification::fake();
        $accra = User::factory()->create(['timezone' => 'Africa/Accra']);
        $tokyo = User::factory()->create(['timezone' => 'Asia/Tokyo']);
        $optedOut = User::factory()->create(['timezone' => 'Africa/Accra']);
        $optedOut->settingsOrDefault()->update(['weekly_review' => false]);
        $accra->tasks()->create(['title' => 'Done last week'])->forceFill(['completed_at' => Carbon::parse('2026-11-11 10:00', 'UTC')])->save();

        $mondayNineAccra = Carbon::parse('2026-11-16 09:05', 'UTC');
        $this->assertSame(1, app(WeeklyReviewService::class)->sendDue($mondayNineAccra));

        Notification::assertSentTo($accra, WeeklyReview::class, fn (WeeklyReview $n) => $n->tasksCompleted === 1);
        Notification::assertNotSentTo($tokyo, WeeklyReview::class);
        Notification::assertNotSentTo($optedOut, WeeklyReview::class);
        $this->assertSame(0, app(WeeklyReviewService::class)->sendDue(Carbon::parse('2026-11-17 09:05', 'UTC')), 'not on Tuesday');
    }

    public function test_weekly_review_is_not_sent_twice_in_a_week(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $monday = Carbon::parse('2026-11-16 09:00', 'UTC');
        $this->travelTo($monday);

        $this->assertSame(1, app(WeeklyReviewService::class)->sendDue($monday));
        $this->assertSame(0, app(WeeklyReviewService::class)->sendDue($monday->copy()->addMinutes(30)));
        $this->assertSame('weekly_review', $user->notifications()->first()->type);
    }

    public function test_fcm_sender_posts_v1_message_and_reports_unregistered_tokens(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $this->opensslConfig());
        openssl_pkey_export($key, $pem, null, $this->opensslConfig());
        $path = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($path, json_encode(['project_id' => 'sira-test', 'client_email' => 'push@sira-test.iam.gserviceaccount.com', 'private_key' => $pem]));
        config(['sira.push.driver' => 'native', 'sira.push.fcm.service_account_json' => $path]);
        $this->app->forgetInstance(PushSender::class);
        $this->app->bind(PushSender::class, PushGateway::class);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
            'fcm.googleapis.com/*' => Http::sequence()
                ->push(['name' => 'projects/sira-test/messages/1'])
                ->push(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);

        $user = User::factory()->create();
        $user->deviceTokens()->create(['platform' => 'android', 'token' => 'fcm-live']);
        $user->notify(new SecurityAlert(SecurityAlert::PASSWORD_CHANGED));

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/sira-test/messages:send'
            && $r->hasHeader('Authorization', 'Bearer ya29.test')
            && $r['message']['token'] === 'fcm-live'
            && $r['message']['data']['route'] === '/profile'
            && $r['message']['android']['notification']['channel_id'] === 'updates');
        $this->assertDatabaseHas('device_tokens', ['token' => 'fcm-live']);

        $user->notify(new SecurityAlert(SecurityAlert::PASSWORD_CHANGED));
        $this->assertDatabaseMissing('device_tokens', ['token' => 'fcm-live']);
        @unlink($path);
    }
}
