<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsOutbound extends Model
{
    use HasFactory;

    protected $table = 'sms_outbound';

    protected $fillable = [
        'workspace_id', 'user_id', 'task_follow_up_id', 'phone',
        'pattern_key', 'pattern_code', 'tokens', 'rendered_preview',
        'segments', 'provider_message_id', 'status', 'error', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'tokens' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(TaskFollowUp::class, 'task_follow_up_id');
    }
}
