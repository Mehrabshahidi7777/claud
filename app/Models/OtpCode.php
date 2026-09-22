<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class OtpCode extends Model
{
    use HasFactory;

    protected $fillable = ['phone', 'code_hash', 'expires_at', 'request_ip'];

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
     * A wrong guess is counted whether or not the code was still usable, so
     * three attempts burn the code even when they arrive after it expired.
     */
    public function matches(string $candidate): bool
    {
        if (Hash::check($candidate, $this->code_hash)) {
            return true;
        }

        $this->increment('attempts');

        return false;
    }

    /**
     * Consumed the moment it works. A code that has logged someone in must not
     * be replayable for the rest of its two minutes.
     */
    public function consume(): void
    {
        $this->update(['consumed_at' => now()]);
    }
}
