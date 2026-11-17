<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Saves profile photos either on the `public` disk (default) or in the `user_avatars` table
 * (SIRA_AVATAR_STORAGE=database). `avatar_path` records where each one lives, so photos stay
 * deletable after the setting changes: database photos are stored as `db:<token>`.
 */
class AvatarStore
{
    private const DB_PREFIX = 'db:';

    /** @return array{path: string, url: string} */
    public function store(User $user, UploadedFile $file): array
    {
        if (config('sira.avatars.storage') === 'database') {
            $token = Str::random(40);
            DB::table('user_avatars')->insert([
                'user_id' => $user->id,
                'token' => $token,
                'mime' => $file->getMimeType() ?: 'image/jpeg',
                'data' => base64_encode((string) file_get_contents($file->getRealPath())),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['path' => self::DB_PREFIX.$token, 'url' => url('/api/v1/avatars/'.$token)];
        }

        $path = $file->store('avatars', 'public');

        return ['path' => $path, 'url' => Storage::disk('public')->url($path)];
    }

    public function delete(?string $path): void
    {
        if (! $path) {
            return;
        }

        if (str_starts_with($path, self::DB_PREFIX)) {
            DB::table('user_avatars')->where('token', substr($path, strlen(self::DB_PREFIX)))->delete();

            return;
        }

        Storage::disk('public')->delete($path);
    }

    /** @return array{mime: string, data: string}|null */
    public function find(string $token): ?array
    {
        $row = DB::table('user_avatars')->where('token', $token)->first(['mime', 'data']);

        return $row ? ['mime' => $row->mime, 'data' => base64_decode($row->data)] : null;
    }
}
