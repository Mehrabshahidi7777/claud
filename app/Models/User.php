<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'phone', 'name', 'email', 'password',
        // Written by the engine rather than a form: the verification stamp
        // when a code is accepted, the opt-out stamp when someone replies
        // "قطع". Leaving them out makes those updates fail in silence.
        'phone_verified_at', 'sms_opted_out_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'sms_opted_out_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Normalising on the way in is what makes the unique index meaningful —
     * a number typed as 0912… and the same number arriving from the provider
     * as 98912… must not become two people.
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => PhoneNumber::normalize($value) ?? $value);
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->withPivot(['role', 'department_id', 'manager_id', 'away_until', 'deactivated_at'])
            ->withTimestamps();
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    public function hasOptedOutOfSms(): bool
    {
        return $this->sms_opted_out_at !== null;
    }

    /**
     * A member created by their manager has never signed in, and that is the
     * point: they are assignable from the moment their number is entered.
     */
    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function localPhone(): ?string
    {
        return PhoneNumber::toLocal($this->phone);
    }

    /**
     * Templates address people by first name. A full name rarely fits inside
     * seventy characters alongside everything else the message has to carry.
     */
    public function firstName(): string
    {
        return explode(' ', trim((string) $this->name))[0] ?: (string) $this->name;
    }
}
