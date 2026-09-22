<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Verified = 'verified';
    case Failed = 'failed';

    /**
     * Only a verified payment releases the subscription. `Paid` means the
     * payer's browser came back saying so, which is a claim rather than a
     * fact until the server-to-server verify agrees.
     */
    public function isSettled(): bool
    {
        return $this === self::Verified;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'در انتظار پرداخت',
            self::Paid => 'پرداخت‌شده، در انتظار تأیید',
            self::Verified => 'تأییدشده',
            self::Failed => 'ناموفق',
        };
    }
}
