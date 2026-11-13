<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\SecurityAlert;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;

/** Shared by the API endpoint and the hosted reset page that email links open. */
class PasswordResetter
{
    /** Returns true on success, or the translated failure message. */
    public function reset(string $email, string $token, string $password): true|string
    {
        $status = Password::reset(
            ['email' => strtolower(trim($email)), 'password' => $password, 'token' => $token],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
                $user->notify(new SecurityAlert(SecurityAlert::PASSWORD_CHANGED));
                event(new PasswordReset($user));
            },
        );

        return $status === Password::PasswordReset ? true : __($status);
    }
}
