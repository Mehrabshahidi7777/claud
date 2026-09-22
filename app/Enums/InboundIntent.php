<?php

namespace App\Enums;

enum InboundIntent: string
{
    case Done = 'done';
    case Defer = 'defer';
    case NewDate = 'new_date';
    case Cancel = 'cancel';
    case OptOut = 'opt_out';
    case Unknown = 'unknown';

    /**
     * An unknown reply earns one clarification and no more. Answering every
     * stray message turns a dedicated line into a chat partner nobody asked
     * for, and each round trip is billed.
     */
    public function deservesReply(): bool
    {
        return $this !== self::Cancel;
    }
}
