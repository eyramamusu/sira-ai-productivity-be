<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AvatarStore;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves profile photos stored in the database. Public like the `/storage` files it replaces
 * (an <img> tag can't send a bearer token); the 40-character random token is the only way in.
 */
class AvatarController extends Controller
{
    public function show(string $token, AvatarStore $avatars): Response
    {
        $avatar = $avatars->find($token);
        abort_if($avatar === null, 404);

        // A new upload gets a new token, so the image at a given URL never changes.
        return response($avatar['data'], 200, [
            'Content-Type' => $avatar['mime'],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
