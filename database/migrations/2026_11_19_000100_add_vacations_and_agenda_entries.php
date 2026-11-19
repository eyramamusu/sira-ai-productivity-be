<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Holidays: no classes meet between these dates, except the ones listed in class_ids.
        Schema::create('timetable_vacations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->json('class_ids')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'updated_at']);
        });

        // One row per occurrence of a class or event the user marked done or took notes on.
        Schema::create('agenda_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('item_type', ['class', 'event']);
            $table->uuid('item_id');
            $table->date('date');
            // A class can meet twice in a day; the slot's start time tells the two apart.
            $table->string('slot_start', 5)->nullable();
            $table->timestamp('done_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'updated_at']);
            $table->index(['user_id', 'item_id', 'date']);
        });

        Schema::table('focus_sessions', function (Blueprint $table) {
            $table->uuid('event_id')->nullable()->after('task_id');
            $table->uuid('class_id')->nullable()->after('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('focus_sessions', function (Blueprint $table) {
            $table->dropColumn(['event_id', 'class_id']);
        });
        Schema::dropIfExists('agenda_entries');
        Schema::dropIfExists('timetable_vacations');
    }
};
