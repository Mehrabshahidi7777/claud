<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use Database\Factories\ApprovalRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalRequest extends Model
{
    /** @use HasFactory<ApprovalRequestFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Unguarded: the decision half of this row — status, decided_by,
     * decided_at — is written by ApprovalService rather than by a form, and a
     * $fillable list drops those silently.
     */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => ApprovalType::class,
            'status' => ApprovalStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'decided_at' => 'datetime',
            'amount' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * The spend that was filed against this approval, if anyone ever filed
     * one. Its absence is what the reconciliation view reports.
     */
    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Pending->value);
    }

    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * How long the leave runs, counted inclusively — 15th to 15th is one day,
     * not zero.
     */
    public function dayCount(): ?int
    {
        if ($this->starts_on === null || $this->ends_on === null) {
            return null;
        }

        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /**
     * Whoever asked can withdraw it, and whoever has to decide it can too —
     * but only while it is still undecided. A decision is a record.
     */
    public function isCancellableBy(User $user): bool
    {
        return $this->status === ApprovalStatus::Pending
            && $this->requester_id === $user->id;
    }
}
