<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A phone that can receive push notifications (FCM token on Android, APNs token on iOS). */
class DeviceToken extends Model
{
    public const PLATFORMS = ['android', 'ios'];

    protected $fillable = ['platform', 'token', 'personal_access_token_id', 'last_seen_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
