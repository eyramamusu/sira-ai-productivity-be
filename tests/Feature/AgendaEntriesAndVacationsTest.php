<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgendaEntriesAndVacationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_vacations_keep_the_classes_that_still_meet(): void
    {
        $this->signIn();
        $class = (string) Str::uuid();
        $id = (string) Str::uuid();

        $this->postJson('/api/v1/timetable/vacations', ['id' => $id, 'name' => 'Christmas break', 'starts_on' => '2026-12-19', 'ends_on' => '2027-01-10', 'class_ids' => [$class]])
            ->assertCreated()->assertJsonPath('data.class_ids', [$class])->assertJsonPath('data.starts_on', '2026-12-19');
        $this->patchJson("/api/v1/timetable/vacations/{$id}", ['class_ids' => []])->assertOk()->assertJsonPath('data.class_ids', []);
        $this->postJson('/api/v1/timetable/vacations', ['name' => 'Backwards', 'starts_on' => '2026-12-19', 'ends_on' => '2026-12-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_on');

        $this->getJson('/api/v1/sync')->assertOk()->assertJsonCount(1, 'timetable_vacations');
        $this->deleteJson("/api/v1/timetable/vacations/{$id}")->assertNoContent();
        $this->getJson('/api/v1/timetable/vacations')->assertJsonCount(0, 'data');
    }

    public function test_each_class_occurrence_keeps_its_own_notes_and_attendance(): void
    {
        $this->signIn();
        $class = (string) Str::uuid();
        $thisWeek = (string) Str::uuid();
        $lastWeek = (string) Str::uuid();

        $this->postJson('/api/v1/agenda-entries', ['id' => $lastWeek, 'item_type' => 'class', 'item_id' => $class, 'date' => '2026-11-12', 'slot_start' => '09:00', 'notes' => 'Limits'])
            ->assertCreated();
        $this->postJson('/api/v1/agenda-entries', ['id' => $thisWeek, 'item_type' => 'class', 'item_id' => $class, 'date' => '2026-11-19', 'slot_start' => '09:00', 'done_at' => '2026-11-19T10:00:00Z'])
            ->assertCreated()->assertJsonPath('data.notes', null)->assertJsonPath('data.date', '2026-11-19');
        // Another device writing the same occurrence uses the same id, so it updates the row.
        $this->postJson('/api/v1/agenda-entries', ['id' => $thisWeek, 'item_type' => 'class', 'item_id' => $class, 'date' => '2026-11-19', 'slot_start' => '09:00', 'notes' => 'Derivatives'])
            ->assertOk()->assertJsonPath('data.notes', 'Derivatives');

        // Un-marking keeps the notes.
        $this->patchJson("/api/v1/agenda-entries/{$thisWeek}", ['done_at' => null])->assertOk()
            ->assertJsonPath('data.done_at', null)->assertJsonPath('data.notes', 'Derivatives');
        $this->getJson("/api/v1/agenda-entries")->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/sync')->assertOk()->assertJsonCount(2, 'agenda_entries');

        $this->postJson('/api/v1/agenda-entries', ['item_type' => 'task', 'item_id' => $class, 'date' => '2026-11-19'])
            ->assertUnprocessable()->assertJsonValidationErrors('item_type');
    }

    public function test_a_replayed_post_or_patch_never_moves_an_entry_to_another_occurrence(): void
    {
        $this->signIn();
        $class = (string) Str::uuid();
        $id = (string) Str::uuid();
        $this->postJson('/api/v1/agenda-entries', ['id' => $id, 'item_type' => 'class', 'item_id' => $class, 'date' => '2026-11-19', 'slot_start' => '09:00'])->assertCreated();

        $this->postJson('/api/v1/agenda-entries', ['id' => $id, 'item_type' => 'event', 'item_id' => (string) Str::uuid(), 'date' => '2026-11-26', 'slot_start' => '14:00', 'notes' => 'Kept'])
            ->assertOk()->assertJsonPath('data.notes', 'Kept')->assertJsonPath('data.item_type', 'class')
            ->assertJsonPath('data.item_id', $class)->assertJsonPath('data.date', '2026-11-19')->assertJsonPath('data.slot_start', '09:00');
        $this->patchJson("/api/v1/agenda-entries/{$id}", ['date' => '2026-12-03', 'notes' => 'Again'])
            ->assertOk()->assertJsonPath('data.date', '2026-11-19')->assertJsonPath('data.notes', 'Again');
    }

    public function test_entries_can_be_listed_for_one_item_and_full_sync_leaves_out_old_ones(): void
    {
        $this->signIn();
        $class = (string) Str::uuid();
        $old = now()->subDays(300)->toDateString();
        $this->postJson('/api/v1/agenda-entries', ['id' => (string) Str::uuid(), 'item_type' => 'class', 'item_id' => $class, 'date' => $old, 'notes' => 'Old'])->assertCreated();
        $this->postJson('/api/v1/agenda-entries', ['id' => (string) Str::uuid(), 'item_type' => 'class', 'item_id' => $class, 'date' => now()->toDateString(), 'notes' => 'New'])->assertCreated();
        $this->postJson('/api/v1/agenda-entries', ['id' => (string) Str::uuid(), 'item_type' => 'event', 'item_id' => (string) Str::uuid(), 'date' => now()->toDateString()])->assertCreated();

        $this->getJson('/api/v1/sync')->assertOk()->assertJsonCount(2, 'agenda_entries');
        $this->getJson("/api/v1/agenda-entries?item_id={$class}")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.notes', 'Old');
    }

    public function test_deleting_a_class_or_event_deletes_its_entries(): void
    {
        $this->signIn();
        $class = $this->postJson('/api/v1/timetable/classes', ['name' => 'Maths', 'slots' => [['weekday' => 4, 'start' => '09:00', 'end' => '10:00']]])->json('data.id');
        $event = $this->postJson('/api/v1/events', ['title' => 'Study group', 'start_date' => '2026-11-19', 'end_date' => '2026-11-19', 'all_day' => true])->json('data.id');
        $this->postJson('/api/v1/agenda-entries', ['id' => (string) Str::uuid(), 'item_type' => 'class', 'item_id' => $class, 'date' => '2026-11-19', 'slot_start' => '09:00', 'notes' => 'x'])->assertCreated();
        $this->postJson('/api/v1/agenda-entries', ['id' => (string) Str::uuid(), 'item_type' => 'event', 'item_id' => $event, 'date' => '2026-11-19'])->assertCreated();
        $since = now()->subSecond()->toIso8601String();

        $this->deleteJson("/api/v1/timetable/classes/{$class}")->assertNoContent();
        $this->getJson('/api/v1/agenda-entries')->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/events/{$event}")->assertNoContent();
        $this->getJson('/api/v1/agenda-entries')->assertJsonCount(0, 'data');
        // Other devices hear about it on their next sync.
        $this->getJson('/api/v1/sync?since='.urlencode($since))->assertOk()->assertJsonCount(2, 'agenda_entries')->assertJsonPath('agenda_entries.0.deleted_at', fn ($v) => $v !== null);
    }

    public function test_entries_are_private_to_their_owner(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/v1/agenda-entries', ['id' => (string) Str::uuid(), 'item_type' => 'event', 'item_id' => (string) Str::uuid(), 'date' => '2026-11-19'])
            ->assertCreated()->json('data.id');

        $this->signIn();
        $this->patchJson("/api/v1/agenda-entries/{$id}", ['notes' => 'mine now'])->assertForbidden();
    }

    public function test_focus_sessions_can_be_for_an_event_or_a_class(): void
    {
        $this->signIn();
        $class = $this->postJson('/api/v1/timetable/classes', ['name' => 'Maths', 'slots' => [['weekday' => 4, 'start' => '09:00', 'end' => '10:00']]])->json('data.id');
        $event = $this->postJson('/api/v1/events', ['title' => 'Study group', 'start_date' => '2026-11-19', 'end_date' => '2026-11-19', 'all_day' => true])->json('data.id');

        $base = ['type' => 'focus', 'started_at' => now()->subMinutes(25)->toIso8601String(), 'duration_seconds' => 1500, 'planned_seconds' => 1500];
        $this->postJson('/api/v1/focus-sessions', $base + ['class_id' => $class])->assertCreated()->assertJsonPath('data.class_id', $class);
        $this->postJson('/api/v1/focus-sessions', $base + ['event_id' => $event])->assertCreated()->assertJsonPath('data.event_id', $event);
        $this->postJson('/api/v1/focus-sessions', $base + ['event_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('event_id');
    }
}
