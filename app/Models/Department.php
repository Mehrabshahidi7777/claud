<?php

namespace App\Models;

use App\Enums\DepartmentKind;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => DepartmentKind::class,
            'is_active' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    /**
     * The people who sit here. Read through the membership pivot rather than
     * a second table, because somebody belongs to a department by virtue of
     * their membership and losing the membership must lose the placement.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user', 'department_id', 'user_id')
            ->withPivot(['role', 'manager_id', 'away_until', 'deactivated_at'])
            ->withTimestamps();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
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
