<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CalendarEventController extends OwnedRowsController
{
    protected function model(): string
    {
        return CalendarEvent::class;
    }

    protected function rows(User $user): HasMany
    {
        return $user->calendarEvents();
    }

    protected function resource(): string
    {
        return CalendarEventResource::class;
    }

    protected function rules(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'id' => ['sometimes', 'uuid'],
            'title' => [$required, 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:200'],
            'all_day' => ['sometimes', 'boolean'],
            'start_date' => [$required, 'date_format:Y-m-d'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_date' => [$required, 'date_format:Y-m-d'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'repeat_rule' => ['sometimes', Rule::in(CalendarEvent::REPEAT_RULES)],
            'repeat_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reminder_minutes' => ['sometimes', 'nullable', 'array', 'max:5'],
            'reminder_minutes.*' => ['integer', 'between:0,40320'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'goal_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('goals', 'id')->where('user_id', $request->user()->id)->whereNull('deleted_at')],
        ];
    }

    protected function crossCheck(array $e): array
    {
        $start = self::ymd($e['start_date'] ?? null);
        $end = self::ymd($e['end_date'] ?? null);
        if ($start && $end && $end < $start) {
            return ['end_date' => 'The event must end on or after the day it starts.'];
        }
        if (empty($e['all_day']) && $start === $end && ! empty($e['start_time']) && ! empty($e['end_time']) && $e['end_time'] <= $e['start_time']) {
            return ['end_time' => 'The event must end after it starts.'];
        }
        $until = self::ymd($e['repeat_until'] ?? null);
        if ($until && $start && $until < $start) {
            return ['repeat_until' => 'The repeat end date must be on or after the start date.'];
        }

        return [];
    }

    private static function ymd(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toDateString();
    }
}
