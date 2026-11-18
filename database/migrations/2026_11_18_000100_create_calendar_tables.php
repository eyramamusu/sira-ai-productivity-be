<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('goal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('location', 200)->nullable();
            $table->boolean('all_day')->default(false);
            $table->date('start_date');
            $table->string('start_time', 5)->nullable();
            $table->date('end_date');
            $table->string('end_time', 5)->nullable();
            $table->enum('repeat_rule', ['none', 'daily', 'weekly', 'monthly', 'yearly'])->default('none');
            $table->date('repeat_until')->nullable();
            $table->json('reminder_minutes')->nullable();
            $table->string('color', 9)->default('#2F6FA8');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'start_date']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('birthdays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->json('reminder_days')->nullable();
            $table->string('reminder_time', 5)->default('09:00');
            $table->string('color', 9)->default('#B8405E');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('timetable_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('timetable_classes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('term_id')->nullable()->constrained('timetable_terms')->nullOnDelete();
            $table->string('name', 120);
            $table->string('code', 30)->nullable();
            $table->enum('kind', ['lecture', 'tutorial', 'lab', 'seminar', 'other'])->default('lecture');
            $table->string('lecturer', 120)->nullable();
            $table->string('room', 120)->nullable();
            $table->string('color', 9)->default('#5B48A2');
            // [{weekday: 1 (Mon) … 7 (Sun), start: "HH:MM", end: "HH:MM"}]
            $table->json('slots');
            $table->unsignedSmallInteger('reminder_minutes')->nullable();
            $table->boolean('show_in_calendar')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        foreach (['timetable_classes', 'timetable_terms', 'birthdays', 'calendar_events'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
