<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\AccountSetup;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Local demo account: demo@sira.test / sira-demo-1. */
    public function run(AccountSetup $setup): void
    {
        if (User::where('email', 'demo@sira.test')->exists()) {
            return;
        }

        $user = User::create([
            'name' => 'Demo User',
            'email' => 'demo@sira.test',
            'password' => 'sira-demo-1',
            'timezone' => 'Africa/Accra',
            'onboarding_completed' => true,
        ]);
        $setup->seedDefaults($user);

        $work = $user->categories()->where('name', 'Work')->first();
        $health = $user->categories()->where('name', 'Health')->first();
        $today = $user->today();

        $goal = $user->goals()->create(['title' => 'Launch the Sira beta', 'target_date' => now()->addMonth()->toDateString()]);

        $proposal = $user->tasks()->create([
            'title' => 'Finish project proposal', 'priority' => 'high', 'category_id' => $work?->id, 'goal_id' => $goal->id,
            'due_date' => $today, 'start_time' => '10:00', 'end_time' => '12:00',
        ]);
        $proposal->subtasks()->create(['title' => 'Research', 'position' => 0]);
        $proposal->subtasks()->create(['title' => 'Write draft', 'position' => 1]);

        $user->tasks()->create(['title' => 'Review pull requests', 'category_id' => $work?->id, 'goal_id' => $goal->id, 'due_date' => $today]);
        $user->tasks()->create(['title' => 'Evening run', 'category_id' => $health?->id, 'due_date' => $today, 'repeat_rule' => 'daily', 'start_time' => '18:00']);
        $user->tasks()->create(['title' => 'Plan next week', 'priority' => 'low', 'due_date' => now()->addDays(2)->toDateString()]);

        $user->habits()->create(['name' => 'Drink water', 'icon' => 'water_drop', 'color' => '#457B9D', 'target_per_day' => 1]);
        $user->habits()->create(['name' => 'Read 20 minutes', 'icon' => 'menu_book', 'color' => '#8B5A3C']);
    }
}
