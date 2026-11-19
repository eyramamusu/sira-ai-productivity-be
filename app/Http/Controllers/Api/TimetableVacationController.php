<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\TimetableVacationResource;
use App\Models\TimetableVacation;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TimetableVacationController extends OwnedRowsController
{
    protected function model(): string
    {
        return TimetableVacation::class;
    }

    protected function rows(User $user): HasMany
    {
        return $user->timetableVacations();
    }

    protected function resource(): string
    {
        return TimetableVacationResource::class;
    }

    protected function rules(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'id' => ['sometimes', 'uuid'],
            'name' => [$required, 'string', 'max:80'],
            'starts_on' => [$required, 'date_format:Y-m-d'],
            'ends_on' => [$required, 'date_format:Y-m-d'],
            // Classes that still meet during the vacation. Ids of since-deleted classes are harmless.
            'class_ids' => ['sometimes', 'nullable', 'array', 'max:100'],
            'class_ids.*' => ['uuid'],
        ];
    }

    protected function crossCheck(array $v): array
    {
        $start = Carbon::parse($v['starts_on']);
        $end = Carbon::parse($v['ends_on']);
        if ($end->lt($start)) {
            return ['ends_on' => 'The vacation must end on or after the day it starts.'];
        }
        if ($start->diffInDays($end) > 400) {
            return ['ends_on' => 'A vacation can be at most 400 days long.'];
        }

        return [];
    }
}
