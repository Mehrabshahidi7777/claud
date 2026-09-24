<?php

namespace App\Models;

use App\Enums\ReceivableStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ReceivableFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Receivable extends Model
{
    /** @use HasFactory<ReceivableFactory> */
    use HasFactory, SoftDeletes;

    /** Unguarded: the sweep writes status and task_id, not a form. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ReceivableStatus::class,
            'issued_on' => 'date',
            'due_on' => 'date',
            'amount' => 'integer',
            'settled_amount' => 'integer',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The chase task the sweep raised for this. Its presence is what stops a
     * second sweep raising a second one.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function outstanding(): int
    {
        return max(0, $this->amount - $this->settled_amount);
    }

    public function isOverdue(): bool
    {
        return $this->status->isOutstanding()
            && $this->due_on->endOfDay()->isPast();
    }

    /**
     * Days past due, which is what an ageing report is built from and what
     * the chase task says out loud.
     */
    public function daysOverdue(): int
    {
        if (! $this->isOverdue()) {
            return 0;
        }

        return (int) CarbonImmutable::parse($this->due_on)->endOfDay()->diffInDays(now());
    }

    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ReceivableStatus::Open->value,
            ReceivableStatus::Partial->value,
        ]);
    }
}
