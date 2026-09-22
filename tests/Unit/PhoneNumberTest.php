<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    #[DataProvider('equivalentForms')]
    public function test_it_folds_every_form_of_one_number_to_the_same_value(string $input): void
    {
        $this->assertSame('989121234567', PhoneNumber::normalize($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function equivalentForms(): array
    {
        return [
            'local with leading zero' => ['09121234567'],
            'national only' => ['9121234567'],
            'international with plus' => ['+989121234567'],
            'international without plus' => ['989121234567'],
            'double zero prefix' => ['00989121234567'],
            'spaced' => ['0912 123 4567'],
            'dashed' => ['0912-123-4567'],
            'persian digits' => ['۰۹۱۲۱۲۳۴۵۶۷'],
            'arabic digits' => ['٠٩١٢١٢٣٤٥٦٧'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_it_rejects_what_is_not_an_iranian_mobile_number(?string $input): void
    {
        $this->assertNull(PhoneNumber::normalize($input));
        $this->assertFalse(PhoneNumber::isValid($input));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function invalidNumbers(): array
    {
        return [
            'too short' => ['0912123'],
            'too long' => ['091212345678'],
            'landline' => ['02188776655'],
            'empty' => [''],
            'letters' => ['not a number'],
            'null' => [null],
        ];
    }

    public function test_it_renders_the_local_form_people_actually_read(): void
    {
        $this->assertSame('09121234567', PhoneNumber::toLocal('989121234567'));
    }
}
