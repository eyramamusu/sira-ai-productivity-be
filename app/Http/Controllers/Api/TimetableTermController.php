<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\TimetableTermResource;
use App\Models\TimetableTerm;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TimetableTermController extends OwnedRowsController
{
    protected function model(): string
    {
        return TimetableTerm::class;
    }

    protected function rows(User $user): HasMany
    {
        return $user->timetableTerms();
    }

    protected function resource(): string
    {
        return TimetableTermResource::class;
    }

    protected function rules(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'id' => ['sometimes', 'uuid'],
            'name' => [$required, 'string', 'max:80'],
            'starts_on' => [$required, 'date_format:Y-m-d'],
            'ends_on' => [$required, 'date_format:Y-m-d'],
        ];
    }

    protected function crossCheck(array $t): array
    {
        $start = Carbon::parse($t['starts_on']);
        $end = Carbon::parse($t['ends_on']);
        if ($end->lt($start)) {
            return ['ends_on' => 'The term must end on or after the day it starts.'];
        }
        if ($start->diffInDays($end) > 400) {
            return ['ends_on' => 'A term can be at most 400 days long.'];
        }

        return [];
    }
}
