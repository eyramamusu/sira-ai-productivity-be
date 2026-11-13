<?php

namespace App\Push;

final readonly class PushMessage
{
    /**
     * @param  string  $route  App path opened when the notification is tapped, e.g. `/premium`.
     * @param  string  $channel  Android notification channel (created by the app).
     */
    public function __construct(
        public string $title,
        public string $body,
        public string $route = '/notifications',
        public string $kind = 'general',
        public string $channel = 'updates',
    ) {}
}
