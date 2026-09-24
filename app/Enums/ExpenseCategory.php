<?php

namespace App\Enums;

/**
 * A short, fixed list rather than a table the customer maintains.
 *
 * Every company that is given a free-form category field ends up with
 * "خرید"، "خريد"، "خرید " and "هزینه خرید" as four separate lines in its own
 * report. A list a manager can hold in their head is worth more than one that
 * fits every company exactly.
 */
enum ExpenseCategory: string
{
    case Payroll = 'payroll';
    case Purchase = 'purchase';
    case Contractor = 'contractor';
    case Rent = 'rent';
    case Transport = 'transport';
    case Utilities = 'utilities';
    case TaxInsurance = 'tax_insurance';
    case Marketing = 'marketing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Payroll => 'حقوق و دستمزد',
            self::Purchase => 'خرید تجهیزات و مواد',
            self::Contractor => 'پیمانکار و دستمزد پروژه',
            self::Rent => 'اجاره',
            self::Transport => 'حمل‌ونقل و ایاب‌ذهاب',
            self::Utilities => 'قبوض و خدمات',
            self::TaxInsurance => 'مالیات و بیمه',
            self::Marketing => 'تبلیغات و بازاریابی',
            self::Other => 'سایر',
        };
    }
}
