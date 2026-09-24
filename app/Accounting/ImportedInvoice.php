<?php

namespace App\Accounting;

use App\Support\PersianText;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * One sales invoice, in the shape this system understands, whatever produced
 * it — a spreadsheet the accountant exported, or an accounting package's own
 * web service later on.
 *
 * Every source normalises into this before anything is written, which is what
 * keeps the importer from growing a branch per vendor.
 */
final class ImportedInvoice
{
    public function __construct(
        public readonly string $reference,
        public readonly string $customerName,
        public readonly ?string $customerPhone,
        public readonly string $title,
        public readonly int $amount,
        public readonly int $settledAmount,
        public readonly CarbonImmutable $issuedOn,
        public readonly CarbonImmutable $dueOn,
    ) {}

    /**
     * Amounts arrive with thousands separators in either script, Persian or
     * Arabic digits, a stray currency word, and sometimes a decimal part that
     * rial does not have. Anything left after folding that is not a digit
     * means the cell was not a number at all.
     */
    public static function parseAmount(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $value = PersianText::foldDigits(trim($raw));
        $value = preg_replace('/[،,\s_]/u', '', $value) ?? $value;
        $value = preg_replace('/(ریال|تومان|rial|toman)/iu', '', $value) ?? $value;

        // A decimal tail is dropped rather than rounded: rial has no subunit,
        // and an exported "1200000.00" must not become null.
        $value = preg_replace('/\.\d+$/', '', trim($value)) ?? $value;

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }

    public static function normalisePhone(?string $raw): ?string
    {
        return $raw === null ? null : PhoneNumber::normalize($raw);
    }
}
