<?php

namespace App\Support;

/**
 * Text arriving by SMS is written on a dozen different keyboards. The same
 * reply reaches us as "۱", "1" or "١", and "می‌رسم" arrives with a real
 * zero-width non-joiner, a plain space, or nothing at all between its parts.
 * Matching raw input against a keyword list is how this layer breaks; folding
 * first is how it stops breaking.
 */
final class PersianText
{
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    /** Arabic letterforms that Persian keyboards produce interchangeably. */
    private const LETTER_FOLDS = [
        'ي' => 'ی',
        'ك' => 'ک',
        'ؤ' => 'و',
        'إ' => 'ا',
        'أ' => 'ا',
        'آ' => 'ا',
        'ة' => 'ه',
    ];

    /** Zero-width joiners, marks and the byte-order mark. */
    private const INVISIBLES = ["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}"];

    /**
     * A Persian SMS is transmitted as UCS-2, which fits 70 characters in a
     * single message. Anything longer is split, and each part after the split
     * carries a header that costs three more characters.
     */
    public const UCS2_SINGLE_LIMIT = 70;

    public const UCS2_MULTIPART_LIMIT = 67;

    public static function foldDigits(string $text): string
    {
        return str_replace(
            [...self::PERSIAN_DIGITS, ...self::ARABIC_DIGITS],
            [...range(0, 9), ...range(0, 9)],
            $text,
        );
    }

    /**
     * The form keyword matching runs against: Latin digits, Persian letters,
     * no invisible characters, single spaces, no trailing punctuation.
     */
    public static function normalize(string $text): string
    {
        $text = str_replace(self::INVISIBLES, ' ', $text);
        $text = strtr($text, self::LETTER_FOLDS);
        $text = self::foldDigits($text);

        // Collapse runs of whitespace, including the newlines a reply keyboard
        // adds on its own.
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        // Drop the punctuation people end a reply with, in both scripts.
        $text = preg_replace('/[.!?؟،؛]+$/u', '', trim($text)) ?? $text;

        return trim(mb_strtolower($text));
    }

    /**
     * How many messages this text bills as. Persian is never GSM-7, so the
     * UCS-2 limits apply even when a template happens to be all Latin.
     */
    public static function segments(string $text): int
    {
        $length = mb_strlen($text);

        if ($length === 0) {
            return 1;
        }

        if ($length <= self::UCS2_SINGLE_LIMIT) {
            return 1;
        }

        return (int) ceil($length / self::UCS2_MULTIPART_LIMIT);
    }

    /**
     * Trim a task title down to what a single-message template can carry. A
     * long title is the fastest way to double the bill on every chase.
     */
    public static function truncate(string $text, int $limit = 25): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit - 1)).'…';
    }
}
