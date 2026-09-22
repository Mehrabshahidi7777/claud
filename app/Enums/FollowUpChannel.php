<?php

namespace App\Enums;

enum FollowUpChannel: string
{
    case Notification = 'notification';
    case Sms = 'sms';
    case Email = 'email';

    public function costsCredit(): bool
    {
        return $this === self::Sms;
    }
}
