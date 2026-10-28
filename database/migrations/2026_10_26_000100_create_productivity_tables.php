<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rows the app creates offline use client-generated UUIDs, so replaying a queued
 * write is idempotent. Everything the app syncs is soft-deleted so deletions reach other devices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('theme', ['light', 'dark', 'system'])->default('system');
            $table->boolean('notifications_enabled')->default(true);
            $table->boolean('habit_reminders')->default(true);
            $table->boolean('task_reminders')->default(true);
            $table->enum('default_priority', ['low', 'medium', 'high'])->default('medium');
            $table->boolean('show_completed_tasks')->default(true);
            $table->string('default_due_time', 5)->default('09:00');
            $table->unsignedTinyInteger('week_starts_on')->default(1);
            $table->unsignedSmallInteger('focus_minutes')->default(25);
            $table->unsignedSmallInteger('break_minutes')->default(5);
            $table->unsignedSmallInteger('daily_task_goal')->default(5);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('color', 9)->default('#8B5A3C');
            $table->string('icon', 40)->default('folder');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->date('target_date')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->enum('status', ['active', 'completed', 'archived'])->default('active');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('goal_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('recurrence_parent_id')->nullable()->index();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->date('due_date')->nullable();
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->enum('repeat_rule', ['none', 'daily', 'weekdays', 'weekly', 'monthly'])->default('none');
            $table->date('repeat_until')->nullable();
            $table->boolean('add_to_calendar')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'due_date']);
            $table->index(['user_id', 'completed_at']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('subtasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->boolean('completed')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('habits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('icon', 40)->default('check_circle');
            $table->string('color', 9)->default('#8B5A3C');
            $table->enum('frequency', ['daily', 'weekly'])->default('daily');
            $table->json('days_of_week')->nullable();
            $table->unsignedTinyInteger('target_per_day')->default(1);
            $table->string('reminder_time', 5)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('habit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('habit_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedTinyInteger('count')->default(1);
            $table->timestamps();
            $table->unique(['habit_id', 'date']);
        });

        Schema::create('focus_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('task_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['focus', 'break'])->default('focus');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('planned_seconds')->default(1500);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->boolean('completed')->default(false);
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'started_at']);
            $table->index(['user_id', 'updated_at']);
        });

        // XP ledger: users.xp is the running sum. One row per award, keyed by what earned it, so undoing the action deletes the row.
        Schema::create('xp_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('reason', 40);
            $table->string('source_type', 40)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'reason', 'source_type', 'source_id'], 'xp_events_source_unique');
        });

        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('achievement_key', 40);
            $table->timestamp('unlocked_at')->useCurrent();
            $table->unique(['user_id', 'achievement_key']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('platform', ['google_play', 'app_store', 'revenuecat', 'manual']);
            $table->string('product_id', 120);
            $table->enum('plan', ['monthly', 'annual']);
            // Purchase token / original transaction id (hidden from API output), needed to re-verify; the hash enforces one account per purchase.
            $table->text('store_transaction_id');
            $table->char('store_transaction_hash', 64)->unique();
            $table->enum('status', ['active', 'grace_period', 'canceled', 'expired', 'revoked', 'pending'])->default('active');
            $table->boolean('auto_renewing')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'expires_at']);
        });

        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });

        Schema::create('coach_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant']);
            $table->string('kind', 20)->default('chat');
            $table->text('content');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['coach_messages', 'ai_usage', 'subscriptions', 'user_achievements', 'xp_events', 'focus_sessions', 'habit_logs', 'habits', 'subtasks', 'tasks', 'goals', 'categories', 'user_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
