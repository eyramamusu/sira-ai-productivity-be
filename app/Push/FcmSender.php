<?php

namespace App\Push;

use App\Billing\Jwt;
use App\Models\DeviceToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Firebase Cloud Messaging HTTP v1, authenticated with a Firebase service account. */
class FcmSender implements PushSender
{
    /** @param  array{project_id: string, client_email: string, private_key: string, token_uri?: string}  $account */
    public function __construct(private array $account) {}

    /** @return array{project_id: string, client_email: string, private_key: string, token_uri?: string}|null */
    public static function account(?string $path): ?array
    {
        $account = $path && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($account) && ! empty($account['project_id']) && ! empty($account['client_email']) && ! empty($account['private_key'])
            ? $account
            : null;
    }

    public function send(DeviceToken $device, PushMessage $message): PushResult
    {
        try {
            $res = Http::withToken($this->accessToken())->timeout(10)->post(
                "https://fcm.googleapis.com/v1/projects/{$this->account['project_id']}/messages:send",
                ['message' => [
                    'token' => $device->token,
                    'notification' => ['title' => $message->title, 'body' => $message->body],
                    'data' => ['route' => $message->route, 'kind' => $message->kind],
                    'android' => [
                        'priority' => 'high',
                        'notification' => ['channel_id' => $message->channel, 'icon' => 'ic_stat_sira', 'color' => '#8B5A3C'],
                    ],
                ]],
            );
        } catch (ConnectionException) {
            return PushResult::Failed;
        } catch (RequestException $e) {
            report($e); // couldn't get an OAuth token: bad or revoked service account

            return PushResult::Failed;
        }

        if ($res->successful()) {
            return PushResult::Delivered;
        }

        $code = $res->json('error.details.0.errorCode') ?? $res->json('error.status');
        if ($res->status() === 404 || $code === 'UNREGISTERED') {
            return PushResult::InvalidToken;
        }

        report(new \RuntimeException("FCM returned {$res->status()}: ".substr($res->body(), 0, 500)));

        return PushResult::Failed;
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm-token:'.md5($this->account['client_email']), now()->addMinutes(50), function () {
            $tokenUri = $this->account['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $assertion = Jwt::sign([], [
                'iss' => $this->account['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $tokenUri,
                'iat' => time(),
                'exp' => time() + 3600,
            ], $this->account['private_key'], 'RS256');

            return (string) Http::asForm()->timeout(10)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ])->throw()->json('access_token');
        });
    }
}
