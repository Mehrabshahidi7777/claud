<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class OtpCode extends Model
{
    use HasFactory;

    /**
     * Never built from request input — the service constructs every row — so
     * guarding the columns only risks dropping one silently. It dropped
     * `consumed_at` when this was a $fillable list, which left a used login
     * code replayable for the rest of its two minutes.
     */
    protected $guarded = [];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public const MAX_ATTEMPTS = 3;

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isLockedOut(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function isUsable(): bool
    {
        return ! $this->isExpired() && ! $this->isConsumed() && ! $this->isLockedOut();
    }

    /**
     * Takes one of the three attempts, in the database, before the guess is
     * checked.
     *
     * Reading `attempts` and incrementing it later let a burst of parallel
     * requests all pass the check before any of them counted, turning three
     * guesses into as many as the attacker could send at once. A conditional
     * UPDATE is atomic: each guess either gets a slot or is refused.
     */
    public function claimAttempt(): bool
    {
        $claimed = static::whereKey($this->getKey())
            ->whereNull('consumed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts');

        return $claimed === 1;
    }

    public function matches(string $candidate): bool
    {
        return Hash::check($candidate, $this->code_hash);
    }

    /**
     * Consumed the moment it works, and only once: two requests carrying the
     * right code at the same moment must not both sign someone in.
     */
    public function consume(): bool
    {
        $consumed = static::whereKey($this->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        return $consumed === 1;
    }
}
