<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalendarItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_can_be_created_updated_and_deleted(): void
    {
        $this->signIn();
        $id = (string) Str::uuid();
        $payload = [
            'id' => $id, 'title' => 'Study group', 'location' => 'Main Library', 'start_date' => '2026-11-18',
            'start_time' => '16:00', 'end_date' => '2026-11-18', 'end_time' => '17:30', 'repeat_rule' => 'weekly',
            'reminder_minutes' => [30], 'color' => '#2F6FA8',
        ];

        $this->postJson('/api/v1/events', $payload)->assertCreated()
            ->assertJsonPath('data.id', $id)->assertJsonPath('data.reminder_minutes', [30])->assertJsonPath('data.all_day', false);
        // An offline replay of the same create updates the row instead of failing.
        $this->postJson('/api/v1/events', ['title' => 'Study group (moved)'] + $payload)->assertOk();
        $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(1, 'data');

        $this->patchJson("/api/v1/events/{$id}", ['location' => 'Room 3'])->assertOk()->assertJsonPath('data.location', 'Room 3');
        $this->deleteJson("/api/v1/events/{$id}")->assertNoContent();
        $this->getJson('/api/v1/events')->assertJsonCount(0, 'data');
    }

    public function test_event_must_end_after_it_starts(): void
    {
        $this->signIn();
        $base = ['title' => 'Talk', 'start_date' => '2026-11-18', 'end_date' => '2026-11-18'];

        $this->postJson('/api/v1/events', $base + ['start_time' => '10:00', 'end_time' => '09:00'])->assertUnprocessable()->assertJsonValidationErrors('end_time');
        $this->postJson('/api/v1/events', ['end_date' => '2026-11-17'] + $base)->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->postJson('/api/v1/events', $base + ['all_day' => true])->assertCreated();
    }

    public function test_birthdays_validate_the_date(): void
    {
        $this->signIn();

        $this->postJson('/api/v1/birthdays', ['name' => 'Ama', 'month' => 11, 'day' => 18, 'birth_year' => 2005, 'reminder_days' => [0, 1]])
            ->assertCreated()->assertJsonPath('data.reminder_time', '09:00')->assertJsonPath('data.reminder_days', [0, 1]);
        $this->postJson('/api/v1/birthdays', ['name' => 'Leap', 'month' => 2, 'day' => 29])->assertCreated();
        $this->postJson('/api/v1/birthdays', ['name' => 'Nope', 'month' => 2, 'day' => 30])->assertUnprocessable()->assertJsonValidationErrors('day');
        $this->postJson('/api/v1/birthdays', ['name' => 'Nope', 'month' => 2, 'day' => 29, 'birth_year' => 2023])->assertUnprocessable()->assertJsonValidationErrors('birth_year');
    }

    public function test_classes_belong_to_the_users_own_term(): void
    {
        $user = $this->signIn();
        $term = $this->postJson('/api/v1/timetable/terms', ['name' => 'Semester 1', 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-18'])
            ->assertCreated()->json('data.id');

        $class = [
            'term_id' => $term, 'name' => 'Data Structures', 'code' => 'CS 201', 'kind' => 'lecture', 'room' => 'LT2',
            'slots' => [['weekday' => 1, 'start' => '08:00', 'end' => '10:00'], ['weekday' => 3, 'start' => '08:00', 'end' => '10:00']],
        ];
        $id = $this->postJson('/api/v1/timetable/classes', $class)->assertCreated()
            ->assertJsonPath('data.slots.1.weekday', 3)->assertJsonPath('data.show_in_calendar', true)->json('data.id');

        $this->postJson('/api/v1/timetable/classes', ['slots' => [['weekday' => 1, 'start' => '10:00', 'end' => '09:00']]] + $class)
            ->assertUnprocessable()->assertJsonValidationErrors('slots.0.end');
        $this->postJson('/api/v1/timetable/classes', ['slots' => [['weekday' => 8, 'start' => '08:00', 'end' => '09:00']]] + $class)
            ->assertUnprocessable()->assertJsonValidationErrors('slots.0.weekday');

        // Someone else's term cannot be used, and someone else's class cannot be changed.
        $other = User::factory()->create();
        $otherTerm = $other->timetableTerms()->create(['name' => 'Theirs', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-01']);
        $this->postJson('/api/v1/timetable/classes', ['term_id' => $otherTerm->id] + $class)->assertUnprocessable()->assertJsonValidationErrors('term_id');
        $this->signIn($other);
        $this->patchJson("/api/v1/timetable/classes/{$id}", ['name' => 'Hijack'])->assertForbidden();
        $this->deleteJson("/api/v1/timetable/classes/{$id}")->assertForbidden();
        $this->assertSame('Data Structures', $user->timetableClasses()->first()->name);
    }

    public function test_terms_must_end_after_they_start(): void
    {
        $this->signIn();
        $this->postJson('/api/v1/timetable/terms', ['name' => 'Bad', 'starts_on' => '2026-12-18', 'ends_on' => '2026-09-07'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_on');
    }

    public function test_sync_includes_calendar_items_and_their_deletions(): void
    {
        $user = $this->signIn();
        $event = $user->calendarEvents()->create(['title' => 'Exam', 'start_date' => '2026-12-01', 'end_date' => '2026-12-01']);
        $user->birthdays()->create(['name' => 'Ama', 'month' => 11, 'day' => 18]);
        $term = $user->timetableTerms()->create(['name' => 'Semester 1', 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-18']);
        $user->timetableClasses()->create(['term_id' => $term->id, 'name' => 'Physics', 'slots' => [['weekday' => 2, 'start' => '09:00', 'end' => '11:00']]]);

        // Sync re-sends rows changed in the same second as `since`, so move past it first.
        $this->travel(5)->seconds();
        $full = $this->getJson('/api/v1/sync')->assertOk()
            ->assertJsonCount(1, 'events')->assertJsonCount(1, 'birthdays')
            ->assertJsonCount(1, 'timetable_terms')->assertJsonCount(1, 'timetable_classes');

        $this->travel(5)->seconds();
        $this->deleteJson("/api/v1/events/{$event->id}")->assertNoContent();
        $this->getJson('/api/v1/sync?since='.urlencode($full->json('server_time')))
            ->assertJsonCount(1, 'events')->assertJsonPath('events.0.id', $event->id)
            ->assertJsonPath('events.0.deleted_at', fn ($v) => $v !== null)
            ->assertJsonCount(0, 'birthdays');
    }

    public function test_deleting_a_goal_unlinks_its_events(): void
    {
        $user = $this->signIn();
        $goal = $user->goals()->create(['title' => 'Pass exams']);
        $event = $this->postJson('/api/v1/events', ['title' => 'Revision', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'goal_id' => $goal->id])
            ->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/goals/{$goal->id}")->assertNoContent();
        $this->assertNull($user->calendarEvents()->find($event)->goal_id);
    }
}
