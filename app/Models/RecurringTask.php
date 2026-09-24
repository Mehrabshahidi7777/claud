<?php

namespace App\Models;

use App\Enums\RecurrenceAnchor;
use App\Enums\RecurrenceUnit;
use App\Enums\TaskPriority;
use Carbon\CarbonImmutable;
use Database\Factories\RecurringTaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecurringTask extends Model
{
    /** @use HasFactory<RecurringTaskFactory> */
    use HasFactory, SoftDeletes;

    /** Unguarded: the sweeper writes next_due_on and occurrences, not a form. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'interval_unit' => RecurrenceUnit::class,
            'anchor' => RecurrenceAnchor::class,
            'priority' => TaskPriority::class,
            'next_due_on' => 'date',
            'last_done_on' => 'date',
            'is_active' => 'boolean',
            'estimated_value' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** A contract with a customer's name on it, rather than a household chore. */
    public function isServiceContract(): bool
    {
        return filled($this->customer_name);
    }

    public function intervalLabel(): string
    {
        return $this->interval_count === 1
            ? 'هر '.$this->interval_unit->label()
            : 'هر '.$this->interval_count.' '.$this->interval_unit->label();
    }

    /**
     * The day the task should appear, which is earlier than the due date by
     * the lead time — that gap is the whole point for a service contract.
     */
    public function raiseOn(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->next_due_on)->subDays($this->lead_days)->startOfDay();
    }

    public function isDueToRaise(): bool
    {
        return $this->is_active && ! $this->raiseOn()->isFuture();
    }

    public function isOverdue(): bool
    {
        return $this->is_active
            && CarbonImmutable::parse($this->next_due_on)->endOfDay()->isPast();
    }

    /**
     * Where the next cycle is counted from. Completion-anchored rows measure
     * from the later of the two dates, so finishing early does not quietly
     * shorten the interval.
     */
    public function nextCycleFrom(?CarbonImmutable $completedOn): CarbonImmutable
    {
        $scheduled = CarbonImmutable::parse($this->next_due_on)->startOfDay();

        if ($this->anchor === RecurrenceAnchor::Scheduled || $completedOn === null) {
            return $scheduled;
        }

        return $completedOn->greaterThan($scheduled) ? $completedOn->startOfDay() : $scheduled;
    }

    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
