<?php

namespace App\Support;

/**
 * One phone number reaches us in half a dozen shapes — 09121234567 from a
 * sign-up form, +989121234567 from a contact import, 989121234567 from the SMS
 * provider's webhook. They are the same person, and the unique index on
 * users.phone only says so if every one of them is folded first.
 *
 * The stored form is 98 followed by the ten national digits, with no plus.
 */
final class PhoneNumber
{
    private const COUNTRY_CODE = '98';

    /**
     * Iranian mobile numbers are ten digits beginning with 9.
     */
    private const NATIONAL_LENGTH = 10;

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', PersianText::foldDigits($raw)) ?? '';

        $national = match (true) {
            // 00989121234567
            str_starts_with($digits, '00'.self::COUNTRY_CODE) => substr($digits, 4),
            // 989121234567
            str_starts_with($digits, self::COUNTRY_CODE) && strlen($digits) === 12 => substr($digits, 2),
            // 09121234567
            str_starts_with($digits, '0') => substr($digits, 1),
            // 9121234567
            default => $digits,
        };

        if (! self::isValidNational($national)) {
            return null;
        }

        return self::COUNTRY_CODE.$national;
    }

    public static function isValid(?string $raw): bool
    {
        return self::normalize($raw) !== null;
    }

    /**
     * How the number is shown back to a Persian-speaking user, who reads
     * 09121234567 and not the stored form.
     */
    public static function toLocal(?string $normalized): ?string
    {
        if ($normalized === null || ! str_starts_with($normalized, self::COUNTRY_CODE)) {
            return $normalized;
        }

        return '0'.substr($normalized, 2);
    }

    private static function isValidNational(string $national): bool
    {
        return strlen($national) === self::NATIONAL_LENGTH
            && str_starts_with($national, '9');
    }
}
