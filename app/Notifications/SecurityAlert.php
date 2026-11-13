<?php

namespace App\Notifications;

use App\Models\User;

/** Tells every other signed-in phone about a new sign-in or a password change. */
class SecurityAlert extends AppNotification
{
    public const NEW_SIGN_IN = 'new_sign_in';

    public const PASSWORD_CHANGED = 'password_changed';

    public function __construct(public string $event, public ?string $device = null)
    {
        parent::__construct();
    }

    public function kind(): string
    {
        return $this->event;
    }

    public function title(User $user): string
    {
        return $this->event === self::NEW_SIGN_IN ? 'New sign-in to your account' : 'Your password was changed';
    }

    public function body(User $user): string
    {
        return $this->event === self::NEW_SIGN_IN
            ? 'Someone signed in on '.($this->device ?: 'a new device').'. If it wasn\'t you, change your password and sign out of all devices.'
            : 'If you didn\'t do this, reset your password now and sign out of all devices.';
    }

    public function route(): string
    {
        return '/profile';
    }

    public function icon(): string
    {
        return 'shield_person';
    }
}
