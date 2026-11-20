<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * List, create (with offline-replay ids), update and delete for simple rows a user owns:
 * calendar events, birthdays, timetable terms and classes.
 */
abstract class OwnedRowsController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    abstract protected function rows(User $user): HasMany;

    /** @return class-string<JsonResource> */
    abstract protected function resource(): string;

    abstract protected function rules(Request $request, bool $creating): array;

    /**
     * Checks that span several fields, run against the row as it will be saved.
     * Returns field => message for each problem.
     *
     * @return array<string, string>
     */
    protected function crossCheck(array $merged): array
    {
        return [];
    }

    /**
     * The fields a replayed POST (one whose id already exists) may change. Defaults to all.
     */
    protected function onReplay(array $data): array
    {
        return $data;
    }

    /** Runs after a row is soft-deleted, for rows that hang off it. */
    protected function afterDelete(User $user, Model $row): void {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->resource()::collection($this->rows($request->user())->orderBy('created_at')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate($this->rules($request, true));
        $row = $this->replayed($this->model(), $data['id'] ?? null, $user);
        $created = $row === null;
        if (! $created) {
            $data = $this->onReplay($data);
        }
        $row ??= $this->rows($user)->make();

        return $this->save($row, $data, $created ? 201 : 200);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = $this->find($request, $id);
        $data = $request->validate($this->rules($request, false));
        unset($data['id']);

        return $this->save($row, $data);
    }

    public function destroy(Request $request, string $id): Response
    {
        $row = $this->find($request, $id);
        $row->delete();
        $this->afterDelete($request->user(), $row);

        return response()->noContent();
    }

    private function find(Request $request, string $id): Model
    {
        $row = $this->model()::findOrFail($id);
        $this->own($request, $row);

        return $row;
    }

    private function save(Model $row, array $data, int $status = 200): JsonResponse
    {
        $row->fill($data);
        if ($errors = $this->crossCheck($row->attributesToArray())) {
            throw ValidationException::withMessages($errors);
        }
        $row->save();

        return response()->json(['data' => ($this->resource())::make($row->fresh())->resolve()], $status);
    }
}
