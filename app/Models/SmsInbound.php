<?php

namespace App\Models;

use App\Enums\InboundIntent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsInbound extends Model
{
    use HasFactory;

    protected $table = 'sms_inbound';

    protected $fillable = [
        'from_phone', 'body', 'normalized_body', 'raw', 'provider_message_id',
        'user_id', 'matched_task_id', 'interpreted_as', 'applied',
        'ignored_reason', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'applied' => 'boolean',
            'received_at' => 'datetime',
            'interpreted_as' => InboundIntent::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matchedTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'matched_task_id');
    }
}
