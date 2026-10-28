<?php

namespace App\Services;

use App\Models\User;

class AccountSetup
{
    /** Default categories and settings for a new account. */
    public function seedDefaults(User $user): void
    {
        foreach (config('sira.default_categories') as $category) {
            $user->categories()->create($category);
        }
        $user->settingsOrDefault();
    }
}
