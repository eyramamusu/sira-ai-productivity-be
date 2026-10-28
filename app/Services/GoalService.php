<?php

namespace App\Services;

use App\Models\Goal;

class GoalService
{
    public function __construct(private GamificationService $gamification) {}

    /**
     * Moves a goal to $progress / $status, awarding the completion XP once and taking it back
     * if the goal is reopened.
     *
     * @return list<string> achievements unlocked
     */
    public function apply(Goal $goal, ?int $progress = null, ?string $status = null): array
    {
        if ($status === 'completed') {
            $progress = 100;
        } elseif ($progress !== null && $progress >= 100 && $status === null) {
            $status = 'completed';
        } elseif ($progress !== null && $progress < 100 && $status === null && $goal->status === 'completed') {
            $status = 'active';
        }

        if ($progress !== null) {
            $goal->progress = max(0, min(100, $progress));
        }
        if ($status !== null) {
            $goal->status = $status;
        }

        $wasCompleted = $goal->completed_at !== null;
        $isCompleted = $goal->status === 'completed';
        $goal->completed_at = $isCompleted ? ($goal->completed_at ?? now()) : null;
        $goal->save();

        if ($isCompleted && ! $wasCompleted) {
            return $this->gamification->award($goal->user, Goal::XP, 'goal_completed', 'goal', $goal->id);
        }
        if (! $isCompleted && $wasCompleted) {
            $this->gamification->revoke($goal->user, 'goal_completed', 'goal', $goal->id);
        }

        return [];
    }

    /** Goals with linked tasks track the share of those tasks that are done. */
    public function syncFromTasks(?Goal $goal): array
    {
        if (! $goal || $goal->status === 'archived') {
            return [];
        }

        $total = $goal->tasks()->count();
        if ($total === 0) {
            return [];
        }
        $done = $goal->tasks()->completed()->count();

        return $this->apply($goal, (int) round($done / $total * 100));
    }
}
