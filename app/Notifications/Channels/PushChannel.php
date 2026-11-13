<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\AppNotification;
use App\Push\PushResult;
use App\Push\PushSender;

/** Sends an AppNotification to every phone the user is signed in on, if they allow push. */
class PushChannel
{
    public function __construct(private PushSender $sender) {}

    public function send(User $user, AppNotification $notification): void
    {
        $settings = $user->settingsOrDefault();
        if (! $settings->notifications_enabled || ! $settings->push_enabled) {
            return;
        }

        $message = $notification->toPush($user);
        foreach ($user->deviceTokens()->get() as $device) {
            if ($this->sender->send($device, $message) === PushResult::InvalidToken) {
                $device->delete();
            }
        }
    }
}
