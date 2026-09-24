<?php

namespace App\Enums;

/**
 * What the next cycle is measured from once a cycle is finished.
 *
 * The distinction matters more than it looks, and getting it wrong is
 * invisible until months later.
 */
enum RecurrenceAnchor: string
{
    case Scheduled = 'scheduled';
    case Completion = 'completion';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'تاریخ ثابت تقویمی',
            self::Completion => 'از زمان انجام واقعی',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            // A bill paid three days late must not push every future bill
            // three days later, and again, and again.
            self::Scheduled => 'برای قبض، بیمه، مالیات و قرارداد — تأخیر در انجام، تاریخ‌های بعدی را جابه‌جا نمی‌کند.',

            // A chiller serviced two months late needs six months from the
            // service, not four.
            self::Completion => 'برای سرویس و نگهداری — دوره‌ی بعدی از روزی که واقعاً انجام شد شمرده می‌شود.',
        };
    }
}
