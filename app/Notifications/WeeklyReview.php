<?php

namespace App\Notifications;

use App\Models\User;

/** Monday-morning recap of last week, sent by `sira:notifications:weekly-review`. */
class WeeklyReview extends AppNotification
{
    public function __construct(public int $tasksCompleted, public int $focusMinutes, public int $habitCheckIns)
    {
        parent::__construct();
    }

    public function kind(): string
    {
        return 'weekly_review';
    }

    public function title(User $user): string
    {
        return $this->tasksCompleted > 0 ? 'Your week in review' : 'A fresh week starts today';
    }

    public function body(User $user): string
    {
        if ($this->tasksCompleted === 0 && $this->focusMinutes === 0 && $this->habitCheckIns === 0) {
            return 'Plan three tasks for today to get the week moving.';
        }

        $parts = [trans_choice('{1} :count task done|[2,*] :count tasks done', $this->tasksCompleted, ['count' => $this->tasksCompleted])];
        if ($this->focusMinutes > 0) {
            $parts[] = $this->focusMinutes >= 60
                ? round($this->focusMinutes / 60, 1).' h of focus'
                : "{$this->focusMinutes} min of focus";
        }
        if ($this->habitCheckIns > 0) {
            $parts[] = trans_choice('{1} :count habit check-in|[2,*] :count habit check-ins', $this->habitCheckIns, ['count' => $this->habitCheckIns]);
        }

        return 'Last week: '.implode(', ', $parts).'. See your stats and plan this week.';
    }

    public function route(): string
    {
        return '/more';
    }

    public function icon(): string
    {
        return 'insights';
    }
}
