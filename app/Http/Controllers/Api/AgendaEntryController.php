<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\AgendaEntryResource;
use App\Models\AgendaEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-occurrence records for classes and events: attended / done, and that day's notes.
 * Clients derive each row's id from the class or event, date and slot, so every device
 * writing the same occurrence updates the same row.
 */
class AgendaEntryController extends OwnedRowsController
{
    protected function model(): string
    {
        return AgendaEntry::class;
    }

    protected function rows(User $user): HasMany
    {
        return $user->agendaEntries();
    }

    protected function resource(): string
    {
        return AgendaEntryResource::class;
    }

    protected function rules(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'id' => ['sometimes', 'uuid'],
            'item_type' => [$required, Rule::in(AgendaEntry::TYPES)],
            'item_id' => [$required, 'uuid'],
            'date' => [$required, 'date_format:Y-m-d'],
            'slot_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'done_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];
    }
}
