<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = ['workspace_id', 'user_id', 'subject_type', 'subject_id', 'event', 'properties'];

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A null actor means the engine did it, which covers most of what ends up
     * here: chases sent, escalations raised, replies applied.
     */
    public static function record(
        Model $subject,
        string $event,
        int $workspaceId,
        ?int $userId = null,
        array $properties = [],
    ): self {
        return self::create([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event' => $event,
            'properties' => $properties ?: null,
        ]);
    }
}
