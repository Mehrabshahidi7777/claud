<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Void = 'void';

    public function isPayable(): bool
    {
        return $this === self::Unpaid;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'پرداخت‌نشده',
            self::Paid => 'پرداخت‌شده',
            self::Void => 'باطل',
        };
    }
}
