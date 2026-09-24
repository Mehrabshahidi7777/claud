<?php

namespace App\Enums;

enum ContractStatus: string
{
    case Active = 'active';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'جاری',
            self::Ended => 'خاتمه‌یافته',
        };
    }
}
