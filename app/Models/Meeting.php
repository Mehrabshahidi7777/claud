<?php

namespace App\Models;

use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Meeting extends Model
{
    /** @use HasFactory<MeetingFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Unguarded on purpose. Half of this row is written by the parser rather
     * than by a form — summary, decisions, processed_by_ai — and a $fillable
     * list drops those silently, which reads to the user as "the model
     * returned nothing".
     */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'held_at' => 'datetime',
            'decisions' => 'array',
            'processed_by_ai' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The tasks that came out of this meeting. This is what answers "why is
     * this on my list" months later.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
