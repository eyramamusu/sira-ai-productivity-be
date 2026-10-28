<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'timezone' => $this->timezone,
            'avatar_url' => $this->avatar_url,
            'onboarding_completed' => (bool) $this->onboarding_completed,
            'xp' => (int) $this->xp,
            'level' => $this->level(),
            'is_premium' => $this->isPremium(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
