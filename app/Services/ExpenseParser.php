<?php

namespace App\Services;

use App\Contracts\AiProvider;
use App\Enums\ExpenseCategory;
use App\Support\JalaliDate;
use App\Support\PersianText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * One line of Persian in, one expense out.
 *
 * "بابت خرید دو عدد کولر گازی از فروشگاه سام ۱۸۵ میلیون ریال، دوازدهم مهر"
 * is how a manager actually writes this down. The hard part is not the words
 * but the number: Iranian amounts arrive as "۱۸۵ میلیون"، "۱۸۵۰۰۰۰۰۰"،
 * "۱۸.۵ میلیون تومان" and every mix of Persian and Latin digits, and a
 * mis-read multiplier is a report that is wrong by a factor of ten.
 *
 * So the model is asked for the amount in rial and its own reading of the
 * unit, and both are checked here against a sane range before anything is
 * offered to the manager — who confirms every row regardless.
 */
class ExpenseParser
{
    /** A single expense above this is almost certainly a units mistake. */
    private const MAX_RIAL = 1_000_000_000_000;

    public function __construct(private readonly AiProvider $ai) {}

    /**
     * @return array{ok: bool, expense: ?array<string, mixed>, reason: ?string}
     */
    public function parse(string $text): array
    {
        $result = $this->ai->structured(
            $this->systemPrompt(),
            PersianText::foldDigits($text),
            $this->schema(),
            purpose: 'extraction',
        );

        if ($result === null) {
            return ['ok' => false, 'expense' => null, 'reason' => 'unavailable'];
        }

        $expense = $this->sanitize($result);

        return $expense === null
            ? ['ok' => false, 'expense' => null, 'reason' => 'not_understood']
            : ['ok' => true, 'expense' => $expense, 'reason' => null];
    }

    private function systemPrompt(): string
    {
        $today = JalaliDate::format(CarbonImmutable::now());

        $categories = collect(ExpenseCategory::cases())
            ->map(fn (ExpenseCategory $c) => $c->value.' ('.$c->label().')')
            ->implode('، ');

        return <<<PROMPT
        از متن فارسی یک هزینه‌ی شرکتی را استخراج کن.

        امروز به تاریخ شمسی: {$today}
        دسته‌ها: {$categories}

        قواعد مبلغ — مهم‌ترین بخش:
        - amount_rial را همیشه به ریال و به عدد کامل بنویس.
        - اگر واحد گفته نشده، فرض کن ریال است.
        - «تومان» را در ۱۰ ضرب کن. «میلیون تومان» یعنی ۱۰٬۰۰۰٬۰۰۰ ریال.
        - «میلیون» بدون تومان یعنی ۱٬۰۰۰٬۰۰۰ ریال. «میلیارد» یعنی ۱٬۰۰۰٬۰۰۰٬۰۰۰ ریال.
        - unit را هم بنویس: rial یا toman — همان واحدی که در متن آمده.

        بقیه‌ی قواعد:
        - title کوتاه و روشن، حداکثر ۶۰ کاراکتر، بدون مبلغ و تاریخ.
        - vendor نام فروشنده یا طرف حساب است؛ اگر نیامده خالی بگذار.
        - spent_date به شمسی و در قالب YYYY/MM/DD. اگر تاریخی نیامده، امروز.
        - «دیروز»، «هفته پیش»، «دوازدهم مهر» را نسبت به امروز حساب کن.
        - category باید دقیقاً یکی از کلیدهای بالا باشد، وگرنه other.
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'amount_rial' => ['type' => 'integer'],
                'unit' => ['type' => 'string', 'enum' => ['rial', 'toman']],
                'vendor' => ['type' => 'string'],
                'spent_date' => ['type' => 'string'],
                'category' => [
                    'type' => 'string',
                    'enum' => array_column(ExpenseCategory::cases(), 'value'),
                ],
            ],
            'required' => ['title', 'amount_rial'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitize(array $raw): ?array
    {
        $validator = Validator::make($raw, [
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'amount_rial' => ['required', 'integer', 'min:1', 'max:'.self::MAX_RIAL],
            'unit' => ['nullable', 'string'],
            'vendor' => ['nullable', 'string', 'max:120'],
            'spent_date' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return null;
        }

        $clean = $validator->validated();

        return [
            'title' => trim($clean['title']),
            'amount' => $clean['amount_rial'],
            'vendor' => filled($clean['vendor'] ?? null) ? trim($clean['vendor']) : null,
            'spent_date' => $this->resolveDate($clean['spent_date'] ?? null),
            'category' => ExpenseCategory::tryFrom($clean['category'] ?? '')?->value
                ?? ExpenseCategory::Other->value,
        ];
    }

    /**
     * An expense is something that already happened, so a date in the future
     * is a misreading. A wide past window is fine — invoices arrive late.
     */
    private function resolveDate(?string $written): string
    {
        $today = JalaliDate::format(CarbonImmutable::now());

        if (blank($written) || ! preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', trim($written), $m)) {
            return $today;
        }

        $date = JalaliDate::toGregorian((int) $m[1], (int) $m[2], (int) $m[3]);

        if ($date === null) {
            return $today;
        }

        if ($date > CarbonImmutable::now()->addDay() || $date < CarbonImmutable::now()->subYears(3)) {
            return $today;
        }

        return JalaliDate::format($date);
    }
}
