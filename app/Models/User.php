<?php

namespace App\Models;

use App\Support\Levels;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'timezone', 'avatar_url', 'avatar_path', 'onboarding_completed'];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = ['xp' => 0, 'timezone' => 'UTC', 'onboarding_completed' => false];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'onboarding_completed' => 'boolean',
            'xp' => 'integer',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value) => strtolower(trim($value)));
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function focusSessions(): HasMany
    {
        return $this->hasMany(FocusSession::class);
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function birthdays(): HasMany
    {
        return $this->hasMany(Birthday::class);
    }

    public function timetableTerms(): HasMany
    {
        return $this->hasMany(TimetableTerm::class);
    }

    public function timetableClasses(): HasMany
    {
        return $this->hasMany(TimetableClass::class);
    }

    public function timetableVacations(): HasMany
    {
        return $this->hasMany(TimetableVacation::class);
    }

    public function agendaEntries(): HasMany
    {
        return $this->hasMany(AgendaEntry::class);
    }

    public function xpEvents(): HasMany
    {
        return $this->hasMany(XpEvent::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function coachMessages(): HasMany
    {
        return $this->hasMany(CoachMessage::class);
    }

    public function settingsOrDefault(): UserSetting
    {
        return $this->settings ?? $this->setRelation('settings', $this->settings()->create())->settings;
    }

    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()->entitled()->latest('expires_at')->first();
    }

    /** Premium follows a verified, unexpired store subscription; nothing else grants it. */
    public function isPremium(): bool
    {
        return $this->subscriptions()->entitled()->exists();
    }

    /** "Today" in the user's own timezone, as `Y-m-d`. */
    public function today(): string
    {
        return $this->now()->toDateString();
    }

    public function now(): Carbon
    {
        return now($this->timezone ?: 'UTC');
    }

    public static function levelForXp(int $xp): int
    {
        return Levels::forXp($xp);
    }

    public function level(): int
    {
        return Levels::forXp((int) $this->xp);
    }
}
