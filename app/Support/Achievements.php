<?php

namespace App\Support;

/** Achievement catalog. Titles are mirrored in src/app/core/achievements.ts for offline toasts. */
class Achievements
{
    /** @return array<string, array{title: string, description: string, icon: string, xp: int, metric: string, threshold: int}> */
    public static function all(): array
    {
        return [
            'first_task' => ['title' => 'First Step', 'description' => 'Complete your first task', 'icon' => 'task_alt', 'xp' => 10, 'metric' => 'tasks_completed', 'threshold' => 1],
            'tasks_10' => ['title' => 'Getting Things Done', 'description' => 'Complete 10 tasks', 'icon' => 'checklist', 'xp' => 25, 'metric' => 'tasks_completed', 'threshold' => 10],
            'tasks_100' => ['title' => 'Centurion', 'description' => 'Complete 100 tasks', 'icon' => 'military_tech', 'xp' => 100, 'metric' => 'tasks_completed', 'threshold' => 100],
            'streak_3' => ['title' => 'On a Roll', 'description' => 'Complete tasks 3 days in a row', 'icon' => 'local_fire_department', 'xp' => 15, 'metric' => 'day_streak', 'threshold' => 3],
            'streak_7' => ['title' => 'Week Warrior', 'description' => 'Complete tasks 7 days in a row', 'icon' => 'whatshot', 'xp' => 30, 'metric' => 'day_streak', 'threshold' => 7],
            'streak_30' => ['title' => 'Unstoppable', 'description' => 'Complete tasks 30 days in a row', 'icon' => 'bolt', 'xp' => 100, 'metric' => 'day_streak', 'threshold' => 30],
            'focus_60' => ['title' => 'Deep Diver', 'description' => 'Focus for 60 minutes in total', 'icon' => 'timer', 'xp' => 20, 'metric' => 'focus_minutes', 'threshold' => 60],
            'focus_600' => ['title' => 'Flow Master', 'description' => 'Focus for 10 hours in total', 'icon' => 'self_improvement', 'xp' => 75, 'metric' => 'focus_minutes', 'threshold' => 600],
            'habit_7' => ['title' => 'Habit Former', 'description' => 'Keep a habit going for 7 days', 'icon' => 'repeat', 'xp' => 30, 'metric' => 'best_habit_streak', 'threshold' => 7],
            'goal_1' => ['title' => 'Goal Getter', 'description' => 'Complete a goal', 'icon' => 'flag', 'xp' => 25, 'metric' => 'goals_completed', 'threshold' => 1],
            'level_5' => ['title' => 'Focused', 'description' => 'Reach level 5', 'icon' => 'star', 'xp' => 50, 'metric' => 'level', 'threshold' => 5],
            'level_10' => ['title' => 'Productivity Pro', 'description' => 'Reach level 10', 'icon' => 'workspace_premium', 'xp' => 100, 'metric' => 'level', 'threshold' => 10],
        ];
    }
}
