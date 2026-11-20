<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\TimetableClassResource;
use App\Models\TimetableClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TimetableClassController extends OwnedRowsController
{
    protected function model(): string
    {
        return TimetableClass::class;
    }

    protected function rows(User $user): HasMany
    {
        return $user->timetableClasses();
    }

    protected function resource(): string
    {
        return TimetableClassResource::class;
    }

    /** The class's attendance marks and per-day notes go with it (soft-deleted, so sync passes it on). */
    protected function afterDelete(User $user, Model $row): void
    {
        $user->agendaEntries()->where('item_type', 'class')->where('item_id', $row->getKey())->delete();
    }

    protected function rules(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'id' => ['sometimes', 'uuid'],
            'term_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('timetable_terms', 'id')->where('user_id', $request->user()->id)->whereNull('deleted_at')],
            'name' => [$required, 'string', 'max:120'],
            'code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'kind' => ['sometimes', Rule::in(TimetableClass::KINDS)],
            'lecturer' => ['sometimes', 'nullable', 'string', 'max:120'],
            'room' => ['sometimes', 'nullable', 'string', 'max:120'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'slots' => [$required, 'array', 'min:1', 'max:14'],
            'slots.*.weekday' => ['required', 'integer', 'between:1,7'],
            'slots.*.start' => ['required', 'date_format:H:i'],
            'slots.*.end' => ['required', 'date_format:H:i'],
            'reminder_minutes' => ['sometimes', 'nullable', 'integer', 'between:0,1440'],
            'show_in_calendar' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function crossCheck(array $c): array
    {
        foreach (array_values($c['slots'] ?? []) as $i => $slot) {
            if ($slot['end'] <= $slot['start']) {
                return ["slots.$i.end" => 'A class must end after it starts.'];
            }
        }

        return [];
    }
}
