<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id', 'title', 'description', 'assignee_id', 'creator_id',
        'due_at', 'priority', 'status', 'may_break_quiet_hours',
        // Written when a reply comes in rather than by a form. Omitting them
        // makes an inbound "انجام شد" close the task without recording when,
        // and a deferral lose the reason it was given.
        'completed_at', 'defer_count', 'defer_reason',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'may_break_quiet_hours' => 'boolean',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(TaskFollowUp::class);
    }

    /**
     * Who the ladder actually chases. An unassigned task falls back to whoever
     * created it rather than going quiet, which is the failure mode this whole
     * product exists to prevent.
     */
    public function responsible(): ?User
    {
        return $this->assignee ?? $this->creator;
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && ! $this->status->isClosed();
    }

    /**
     * Hours past the deadline, which is what the escalation template reports
     * to the manager. Measured from the deadline itself rather than from when
     * the message goes out, so a night of deferred sending does not understate
     * the delay.
     */
    public function hoursOverdue(): int
    {
        if ($this->due_at === null) {
            return 0;
        }

        return max(0, (int) $this->due_at->diffInHours(now()));
    }

    public function scopeChaseable(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            TaskStatus::Done->value,
            TaskStatus::Cancelled->value,
            TaskStatus::Deferred->value,
        ]);
    }

    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }
}
