<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

abstract class Controller
{
    /** 403 unless $model belongs to the signed-in user. Also binds the user so XP updates hit the same instance. */
    protected function own(Request $request, Model $model): void
    {
        $user = $request->user();
        abort_unless($model->getAttribute('user_id') === $user->id, 403, 'This item belongs to another account.');
        $model->setRelation('user', $user);
    }

    /**
     * Offline clients send their own UUIDs, so a replayed POST must update the row it created
     * earlier instead of failing. Returns that row (restored if it was deleted), or null for a new id.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    protected function replayed(string $class, ?string $id, User $user): ?Model
    {
        if (! $id) {
            return null;
        }

        $existing = $class::withTrashed()->find($id);
        if (! $existing) {
            return null;
        }
        abort_unless($existing->user_id === $user->id, 409, 'This id is already used by another item.');
        if ($existing->trashed()) {
            $existing->restore();
        }
        $existing->setRelation('user', $user);

        return $existing;
    }
}
