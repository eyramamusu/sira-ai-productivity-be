<?php

namespace App\Push;

use App\Billing\Jwt;
use App\Models\DeviceToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Apple Push Notification service over HTTP/2 with token (.p8 key) authentication. */
class ApnsSender implements PushSender
{
    /** @param  array{team_id: string, key_id: string, private_key_path: string, bundle_id: string, environment: string}  $config */
    public function __construct(private array $config) {}

    public static function configured(array $config): bool
    {
        return ! empty($config['team_id']) && ! empty($config['key_id']) && ! empty($config['private_key_path']) && is_readable($config['private_key_path']);
    }

    public function send(DeviceToken $device, PushMessage $message): PushResult
    {
        $host = $this->config['environment'] === 'sandbox' ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';

        try {
            $res = Http::withOptions(['version' => 2.0])
                ->withHeaders([
                    'authorization' => 'bearer '.$this->providerToken(),
                    'apns-topic' => $this->config['bundle_id'],
                    'apns-push-type' => 'alert',
                    'apns-priority' => '10',
                ])
                ->timeout(10)
                ->post("{$host}/3/device/{$device->token}", [
                    'aps' => ['alert' => ['title' => $message->title, 'body' => $message->body], 'sound' => 'default'],
                    'route' => $message->route,
                    'kind' => $message->kind,
                ]);
        } catch (ConnectionException) {
            return PushResult::Failed;
        }

        if ($res->successful()) {
            return PushResult::Delivered;
        }

        if ($res->status() === 410 || in_array($res->json('reason'), ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
            return PushResult::InvalidToken;
        }

        report(new \RuntimeException("APNs returned {$res->status()}: ".substr($res->body(), 0, 500)));

        return PushResult::Failed;
    }

    /** Apple accepts a provider token for up to an hour and throttles refreshing more than every 20 minutes. */
    private function providerToken(): string
    {
        return Cache::remember('apns-token:'.$this->config['key_id'], now()->addMinutes(45), fn () => Jwt::sign(
            ['kid' => $this->config['key_id']],
            ['iss' => $this->config['team_id'], 'iat' => time()],
            (string) file_get_contents($this->config['private_key_path']),
            'ES256',
        ));
    }
}
