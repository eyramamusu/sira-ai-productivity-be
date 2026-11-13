<?php

namespace App\Push;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;

/** Used when push credentials aren't configured: the notification still lands in the in-app inbox. */
class LogSender implements PushSender
{
    public function send(DeviceToken $device, PushMessage $message): PushResult
    {
        Log::info('Push not sent (no credentials for this platform)', [
            'device_id' => $device->id,
            'platform' => $device->platform,
            'kind' => $message->kind,
            'title' => $message->title,
        ]);

        return PushResult::Delivered;
    }
}
