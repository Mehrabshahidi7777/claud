<?php

namespace App\Models;

use App\Enums\ContractKind;
use App\Enums\ContractStatus;
use App\Enums\PartyType;
use Carbon\CarbonImmutable;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    /** @use HasFactory<ContractFactory> */
    use HasFactory, SoftDeletes;

    /** Unguarded: renewals are written by the service, not by one form. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => ContractKind::class,
            'party_type' => PartyType::class,
            'status' => ContractStatus::class,
            'starts_on' => 'date',
            'expires_on' => 'date',
            'ended_on' => 'date',
            'auto_renews' => 'boolean',
            'value' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** The member this contract is with, for a staff contract. */
    public function party(): BelongsTo
    {
        return $this->belongsTo(User::class, 'party_user_id');
    }

    public function terms(): HasMany
    {
        return $this->hasMany(ContractTerm::class)->orderByDesc('starts_on');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function isActive(): bool
    {
        return $this->status === ContractStatus::Active;
    }

    public function hasExpired(): bool
    {
        return $this->isActive()
            && CarbonImmutable::parse($this->expires_on)->endOfDay()->isPast();
    }

    /** Inside the notice window but not yet expired. */
    public function isExpiringSoon(): bool
    {
        return $this->isActive()
            && ! $this->hasExpired()
            && ! $this->noticeFrom()->isFuture();
    }

    public function noticeFrom(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->expires_on)
            ->subDays($this->notice_days)
            ->startOfDay();
    }

    /**
     * Negative once it has lapsed, which is what the screen wants: "۱۲ روز
     * مانده" and "۱۲ روز گذشته" are the same measurement with a sign.
     */
    public function daysToExpiry(): int
    {
        return (int) CarbonImmutable::now()->startOfDay()
            ->diffInDays(CarbonImmutable::parse($this->expires_on)->startOfDay(), false);
    }

    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ContractStatus::Active->value);
    }
}
