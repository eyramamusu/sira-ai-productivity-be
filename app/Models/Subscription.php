<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    /** Statuses that grant premium until `expires_at` (a cancelled plan runs to the end of its period). */
    public const ENTITLED_STATUSES = ['active', 'grace_period', 'canceled'];

    protected $fillable = [
        'platform', 'product_id', 'plan', 'store_transaction_id', 'store_transaction_hash', 'status',
        'auto_renewing', 'starts_at', 'expires_at', 'last_verified_at', 'raw',
    ];

    protected $hidden = ['store_transaction_id', 'store_transaction_hash', 'raw'];

    protected function casts(): array
    {
        return [
            'auto_renewing' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeEntitled(Builder $query): void
    {
        $query->whereIn('status', self::ENTITLED_STATUSES)->where('expires_at', '>', now());
    }

    public static function hashToken(string $platform, string $token): string
    {
        return hash('sha256', $platform.'|'.$token);
    }

    public function toApi(): array
    {
        return [
            'platform' => $this->platform,
            'product_id' => $this->product_id,
            'plan' => $this->plan,
            'status' => $this->status,
            'auto_renewing' => $this->auto_renewing,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
