<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Signing out (or any revoked API token) removes the device, so pushes stop with the session.
            $table->foreignId('personal_access_token_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('platform', ['android', 'ios']);
            $table->string('token')->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::table('user_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminder_lead_minutes')->default(10)->after('task_reminders');
            $table->boolean('daily_summary')->default(true)->after('reminder_lead_minutes');
            $table->string('daily_summary_time', 5)->default('08:00')->after('daily_summary');
            $table->boolean('streak_reminders')->default(true)->after('daily_summary_time');
            $table->string('streak_reminder_time', 5)->default('20:00')->after('streak_reminders');
            $table->boolean('goal_reminders')->default(true)->after('streak_reminder_time');
            $table->boolean('push_enabled')->default(true)->after('goal_reminders');
            $table->boolean('weekly_review')->default(true)->after('push_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn([
                'reminder_lead_minutes', 'daily_summary', 'daily_summary_time', 'streak_reminders',
                'streak_reminder_time', 'goal_reminders', 'push_enabled', 'weekly_review',
            ]);
        });

        Schema::dropIfExists('device_tokens');
    }
};
