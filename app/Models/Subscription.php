<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /** Written by the billing service, never from request input. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminders_sent' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function plan(): array
    {
        return config("payment.plans.$this->plan_key", []);
    }

    public function planName(): string
    {
        return $this->plan()['name'] ?? $this->plan_key;
    }

    public function grantsAccess(): bool
    {
        return $this->status->grantsAccess() && $this->effectiveEnd()->isFuture();
    }

    /**
     * The moment access actually stops: the end of the grace period once one
     * has started, otherwise the end of the paid term.
     */
    public function effectiveEnd(): Carbon
    {
        return $this->grace_ends_at ?? $this->ends_at;
    }

    public function daysUntilExpiry(): int
    {
        return (int) ceil(now()->diffInDays($this->ends_at, false));
    }

    /**
     * Reminders are recorded by the day-count they were sent for, so an
     * hourly sweep cannot text the same warning four times.
     */
    public function hasBeenRemindedAt(int $daysBefore): bool
    {
        return in_array($daysBefore, $this->reminders_sent ?? [], true);
    }

    public function recordReminder(int $daysBefore): void
    {
        $this->update([
            'reminders_sent' => array_values(array_unique([...($this->reminders_sent ?? []), $daysBefore])),
        ]);
    }
}
