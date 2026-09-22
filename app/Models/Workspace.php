<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workspace extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'timezone', 'settings', 'sms_enabled', 'sms_quota', 'sms_used'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'sms_enabled' => 'boolean',
            'sms_period_started_at' => 'datetime',
        ];
    }

    public function members(): BelongsToMany
    {
        // Named explicitly: Laravel would infer `user_workspace` from the
        // alphabetical convention, and `workspace_user` reads the way the
        // relationship actually runs.
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot(['role', 'manager_id', 'away_until', 'deactivated_at'])
            ->withTimestamps();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Read a follow-up setting, falling back to config/followup.php. Every
     * timing in the engine goes through here so a workspace can tune its own
     * ladder without the defaults being duplicated anywhere.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key)
            ?? config("followup.$key", $default);
    }

    public function timezone(): string
    {
        return $this->timezone ?: config('app.timezone');
    }

    public function hasSmsCredit(int $segments = 1): bool
    {
        return $this->sms_used + $segments <= $this->sms_quota;
    }

    public function remainingSmsCredit(): int
    {
        return max(0, $this->sms_quota - $this->sms_used);
    }

    /**
     * Counted in parts rather than messages, because parts are what the
     * provider bills and what the quota is meant to cap.
     */
    public function consumeSmsCredit(int $segments = 1): void
    {
        $this->increment('sms_used', $segments);
    }
}
