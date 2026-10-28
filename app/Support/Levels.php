<?php

namespace App\Support;

/** Level n → n+1 costs 50·n XP, so level 2 is at 50, level 3 at 150, level 4 at 300… */
class Levels
{
    private const TITLES = [
        1 => 'Beginner', 2 => 'Starter', 3 => 'Planner', 4 => 'Achiever', 5 => 'Focused',
        7 => 'Consistent', 10 => 'Productivity Pro', 15 => 'Master', 20 => 'Legend',
    ];

    public static function threshold(int $level): int
    {
        return 25 * $level * ($level - 1);
    }

    public static function forXp(int $xp): int
    {
        $level = 1;
        while (self::threshold($level + 1) <= $xp) {
            $level++;
        }

        return $level;
    }

    /** @return array{current: int, required: int} XP into the current level and XP needed to finish it. */
    public static function progress(int $xp): array
    {
        $level = self::forXp($xp);

        return ['current' => $xp - self::threshold($level), 'required' => 50 * $level];
    }

    public static function title(int $level): string
    {
        $title = self::TITLES[1];
        foreach (self::TITLES as $min => $name) {
            if ($level >= $min) {
                $title = $name;
            }
        }

        return $title;
    }
}
