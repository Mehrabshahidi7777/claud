<?php

namespace App\Enums;

enum PartyType: string
{
    case Employee = 'employee';
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Authority = 'authority';

    public function label(): string
    {
        return match ($this) {
            self::Employee => 'کارمند',
            self::Customer => 'مشتری',
            self::Supplier => 'تأمین‌کننده',
            self::Authority => 'نهاد صادرکننده',
        };
    }
}
