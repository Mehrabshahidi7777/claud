<?php

namespace Tests\Unit;

use App\Support\PersianText;
use PHPUnit\Framework\TestCase;

class PersianTextTest extends TestCase
{
    public function test_it_folds_persian_and_arabic_digits_to_latin(): void
    {
        $this->assertSame('1402', PersianText::foldDigits('۱۴۰۲'));
        $this->assertSame('1402', PersianText::foldDigits('١٤٠٢'));
        $this->assertSame('1', PersianText::normalize('۱'));
    }

    public function test_it_folds_arabic_letterforms_persian_keyboards_produce(): void
    {
        // The same word typed with the Arabic yeh and kaf must match the
        // Persian forms the keyword list is written in.
        $this->assertSame(PersianText::normalize('یک'), PersianText::normalize('يك'));
    }

    public function test_it_strips_the_zero_width_characters_that_break_matching(): void
    {
        $this->assertSame('نمی رسم', PersianText::normalize("نمی\u{200C}رسم"));
    }

    public function test_it_drops_trailing_punctuation_in_both_scripts(): void
    {
        $this->assertSame('انجام شد', PersianText::normalize('انجام شد.'));
        $this->assertSame('انجام شد', PersianText::normalize('انجام شد!'));
        $this->assertSame('انجام شد', PersianText::normalize('انجام شد؟'));
    }

    public function test_it_collapses_whitespace_and_newlines(): void
    {
        $this->assertSame('انجام شد', PersianText::normalize("  انجام\n  شد  "));
    }

    public function test_a_persian_message_bills_as_one_part_up_to_seventy_characters(): void
    {
        $this->assertSame(1, PersianText::segments(str_repeat('ا', 70)));
        $this->assertSame(2, PersianText::segments(str_repeat('ا', 71)));
    }

    public function test_beyond_one_part_each_segment_carries_only_sixty_seven_characters(): void
    {
        // 134 = 2 × 67 exactly; one more character forces a third part.
        $this->assertSame(2, PersianText::segments(str_repeat('ا', 134)));
        $this->assertSame(3, PersianText::segments(str_repeat('ا', 135)));
    }

    public function test_an_empty_message_still_counts_as_one(): void
    {
        $this->assertSame(1, PersianText::segments(''));
    }

    public function test_it_truncates_long_titles_so_a_chase_stays_one_message(): void
    {
        $title = 'نصب و راه‌اندازی کولر گازی طبقه سوم ساختمان مرکزی';

        $truncated = PersianText::truncate($title, 25);

        $this->assertLessThanOrEqual(25, mb_strlen($truncated));
        $this->assertStringEndsWith('…', $truncated);
    }

    public function test_a_short_title_is_left_exactly_as_it_is(): void
    {
        $this->assertSame('نصب کولر', PersianText::truncate('نصب کولر', 25));
    }
}
