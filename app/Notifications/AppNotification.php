<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Every server notification is stored for the in-app inbox (`GET /notifications`) and,
 * when `$push` is true, also sent to the user's phones.
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Inbox-only notifications are ones the app already shows while it's open (e.g. achievement toasts). */
    protected bool $push = true;

    public function __construct()
    {
        $this->afterCommit();
    }

    abstract public function kind(): string;

    abstract public function title(User $user): string;

    abstract public function body(User $user): string;

    /** App path opened from the inbox or a tapped push. */
    public function route(): string
    {
        return '/notifications';
    }

    /** Material Symbols icon shown in the inbox. */
    public function icon(): string
    {
        return 'notifications';
    }

    /** @return list<string|class-string> */
    public function via(User $user): array
    {
        return $this->push ? ['database', PushChannel::class] : ['database'];
    }

    /** @return array{kind: string, title: string, body: string, route: string, icon: string} */
    public function toArray(User $user): array
    {
        return [
            'kind' => $this->kind(),
            'title' => $this->title($user),
            'body' => $this->body($user),
            'route' => $this->route(),
            'icon' => $this->icon(),
        ];
    }

    public function toPush(User $user): PushMessage
    {
        return new PushMessage($this->title($user), $this->body($user), $this->route(), $this->kind());
    }

    public function databaseType(User $user): string
    {
        return $this->kind();
    }
}
