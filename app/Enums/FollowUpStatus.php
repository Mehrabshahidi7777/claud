<?php

namespace App\Enums;

enum FollowUpStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';

    /**
     * Only pending rows are ever picked up by the sweep. Everything else is
     * history, including the skips — knowing why a message did not go out is
     * worth as much as knowing that one did.
     */
    public function isActionable(): bool
    {
        return $this === self::Pending;
    }
}
