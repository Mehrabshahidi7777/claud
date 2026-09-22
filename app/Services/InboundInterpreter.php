<?php

namespace App\Services;

use App\Enums\InboundIntent;
use App\Support\JalaliDate;
use App\Support\PersianText;
use Carbon\CarbonImmutable;

/**
 * Turns the text of a reply into an intent. Every comparison runs against the
 * normalised form, so "۱", "1" and "١" are one keyword rather than three, and
 * "نمی‌رسم" matches whether or not the sender's keyboard produced a real
 * zero-width non-joiner.
 */
class InboundInterpreter
{
    /**
     * @return array{intent: InboundIntent, date: ?CarbonImmutable}
     */
    public function interpret(string $body): array
    {
        $text = PersianText::normalize($body);

        foreach (['opt_out', 'cancel', 'done', 'defer'] as $group) {
            if ($this->matches($text, (array) config("sms.keywords.$group", []))) {
                return [
                    'intent' => InboundIntent::from($group === 'opt_out' ? 'opt_out' : $group),
                    'date' => null,
                ];
            }
        }

        if ($date = $this->parseDate($text)) {
            return ['intent' => InboundIntent::NewDate, 'date' => $date];
        }

        return ['intent' => InboundIntent::Unknown, 'date' => null];
    }

    /**
     * Keywords match the whole reply, not a fragment of it. Substring matching
     * turns "کار ۲ روز دیگه تمام می‌شود" into a deferral the sender never meant.
     *
     * @param  list<string>  $keywords
     */
    private function matches(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($text === PersianText::normalize($keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Accepts a Jalali date in the shapes people actually type, plus the two
     * relative words that come up constantly. Returns null for anything else
     * rather than guessing — a wrong deadline is worse than asking again.
     */
    private function parseDate(string $text): ?CarbonImmutable
    {
        if ($text === PersianText::normalize('فردا')) {
            return CarbonImmutable::tomorrow();
        }

        if ($text === PersianText::normalize('پس فردا')) {
            return CarbonImmutable::tomorrow()->addDay();
        }

        // 1404/07/15, 1404-07-15 and 1404.07.15 all reach here with Latin
        // digits, PersianText::normalize() having folded them already.
        if (! preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $text, $matches)) {
            return null;
        }

        [, $year, $month, $day] = $matches;

        return JalaliDate::toGregorian((int) $year, (int) $month, (int) $day);
    }
}
