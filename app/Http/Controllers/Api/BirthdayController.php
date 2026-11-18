<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\BirthdayResource;
use App\Models\Birthday;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

class BirthdayController extends OwnedRowsController
{
    protected function model(): string
    {
        return Birthday::class;
    }

    protected function rows(User $user): HasMany
    {
        return $user->birthdays();
    }

    protected function resource(): string
    {
        return BirthdayResource::class;
    }

    protected function rules(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'id' => ['sometimes', 'uuid'],
            'name' => [$required, 'string', 'max:120'],
            'month' => [$required, 'integer', 'between:1,12'],
            'day' => [$required, 'integer', 'between:1,31'],
            'birth_year' => ['sometimes', 'nullable', 'integer', 'between:1900,'.now()->year],
            'reminder_days' => ['sometimes', 'nullable', 'array', 'max:4'],
            'reminder_days.*' => ['integer', 'between:0,30'],
            'reminder_time' => ['sometimes', 'date_format:H:i'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function crossCheck(array $b): array
    {
        // 2024 is a leap year, so 29 February is allowed.
        if (! checkdate((int) $b['month'], (int) $b['day'], 2024)) {
            return ['day' => 'That date does not exist.'];
        }
        if (! empty($b['birth_year']) && ! checkdate((int) $b['month'], (int) $b['day'], (int) $b['birth_year'])) {
            return ['birth_year' => "{$b['birth_year']} had no 29 February."];
        }

        return [];
    }
}
