<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Achievements;

/** Inbox record of an unlock; the app shows its own toast at the moment it happens. */
class AchievementUnlocked extends AppNotification
{
    protected bool $push = false;

    public function __construct(public string $achievement)
    {
        parent::__construct();
    }

    public function kind(): string
    {
        return 'achievement_unlocked';
    }

    public function title(User $user): string
    {
        return 'Achievement unlocked: '.(Achievements::all()[$this->achievement]['title'] ?? 'New badge');
    }

    public function body(User $user): string
    {
        $def = Achievements::all()[$this->achievement] ?? null;

        return $def ? "{$def['description']} · +{$def['xp']} XP" : 'You earned a new achievement.';
    }

    public function route(): string
    {
        return '/achievements';
    }

    public function icon(): string
    {
        return Achievements::all()[$this->achievement]['icon'] ?? 'emoji_events';
    }
}
