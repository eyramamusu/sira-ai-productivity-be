<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\AgendaEntryResource;
use App\Models\AgendaEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Per-occurrence records for classes and events: attended / done, and that day's notes.
 * Clients derive each row's id from the class or event, date and slot, so every device
 * writing the same occurrence updates the same row.
 */
class AgendaEntryController extends OwnedRowsController
{
    /** The fields that make up an entry's id; they never change once the row exists. */
    private const KEY_FIELDS = ['item_type', 'item_id', 'date', 'slot_start'];

    /** All entries, or with ?item_id= just one class's or event's (older notes that sync leaves out). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $itemId = $request->validate(['item_id' => ['sometimes', 'uuid']])['item_id'] ?? null;

        return AgendaEntryResource::collection(
            $this->rows($request->user())->when($itemId, fn ($q) => $q->where('item_id', $itemId))->orderBy('date')->get()
        );
    }

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
        // The key fields are set on create only: the id is derived from them.
        $key = $creating ? 'required' : 'exclude';

        return [
            'id' => ['sometimes', 'uuid'],
            'item_type' => [$key, Rule::in(AgendaEntry::TYPES)],
            'item_id' => [$key, 'uuid'],
            'date' => [$key, 'date_format:Y-m-d'],
            'slot_start' => [$creating ? 'sometimes' : 'exclude', 'nullable', 'date_format:H:i'],
            'done_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];
    }

    /** A replayed or second-device POST updates marks and notes, never which occurrence the row is. */
    protected function onReplay(array $data): array
    {
        return array_diff_key($data, array_flip(self::KEY_FIELDS));
    }
}
