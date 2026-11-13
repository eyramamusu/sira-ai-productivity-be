<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/** Push tokens for the phones a user is signed in on. */
class DeviceController extends Controller
{
    /** Registers (or moves) a push token. Tokens are tied to the API session, so signing out removes them. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(DeviceToken::PLATFORMS)],
        ]);

        $accessToken = $request->user()->currentAccessToken();
        $device = DeviceToken::firstOrNew(['token' => $data['token']]);
        // A token that belonged to another account on this phone now belongs to whoever signed in.
        $device->user()->associate($request->user());
        $device->fill([
            'platform' => $data['platform'],
            'personal_access_token_id' => $accessToken instanceof PersonalAccessToken && $accessToken->exists ? $accessToken->getKey() : null,
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['message' => 'Device registered.'], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);
        $request->user()->deviceTokens()->where('token', $data['token'])->delete();

        return response()->json(['message' => 'Device removed.']);
    }
}
