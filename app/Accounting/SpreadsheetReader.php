<?php

namespace App\Accounting;

use App\Support\JalaliDate;
use App\Support\PersianText;
use Carbon\CarbonImmutable;

/**
 * Reads the invoice export an accountant actually produces.
 *
 * CSV rather than xlsx on purpose: every accounting package in the country
 * exports it, every accountant can save one from Excel, and it needs no
 * dependency to read. The cost is that the file has to be saved as CSV UTF-8,
 * which the import screen says out loud.
 *
 * Three things break real Iranian exports and are handled here: Excel writes
 * a UTF-8 BOM on the first header, Windows locales export with semicolons
 * instead of commas, and dates come back Jalali as often as Gregorian.
 */
class SpreadsheetReader
{
    /**
     * Header spellings seen in the wild, mapped to the field they fill. More
     * forgiving than it looks because an accountant renaming a column should
     * not be a support ticket.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'reference' => ['reference', 'شماره فاکتور', 'شماره سند', 'شماره', 'کد فاکتور'],
        'customer_name' => ['customer', 'مشتری', 'نام مشتری', 'طرف حساب', 'خریدار'],
        'customer_phone' => ['phone', 'تلفن', 'موبایل', 'شماره تماس'],
        'title' => ['title', 'شرح', 'بابت', 'موضوع', 'توضیحات'],
        'amount' => ['amount', 'مبلغ', 'مبلغ کل', 'جمع کل', 'مبلغ فاکتور'],
        'settled' => ['settled', 'دریافتی', 'پرداخت شده', 'وصول شده', 'مبلغ دریافتی'],
        'issued_on' => ['issued', 'تاریخ', 'تاریخ صدور', 'تاریخ فاکتور'],
        'due_on' => ['due', 'سررسید', 'تاریخ سررسید', 'مهلت'],
    ];

    /**
     * @return array{invoices: list<ImportedInvoice>, skipped: list<array{row: int, reason: string}>}
     */
    public function read(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ['invoices' => [], 'skipped' => []];
        }

        $invoices = [];
        $skipped = [];
        $map = null;
        $rowNumber = 0;

        try {
            $delimiter = $this->sniffDelimiter($path);

            while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNumber++;

                if ($cells === [null] || $cells === []) {
                    continue;
                }

                if ($map === null) {
                    $map = $this->mapHeaders($cells);

                    if ($map === null) {
                        return [
                            'invoices' => [],
                            'skipped' => [['row' => 1, 'reason' => 'ستون‌های مشتری، مبلغ و سررسید پیدا نشد.']],
                        ];
                    }

                    continue;
                }

                $invoice = $this->toInvoice($cells, $map, $rowNumber);

                if (is_string($invoice)) {
                    $skipped[] = ['row' => $rowNumber, 'reason' => $invoice];

                    continue;
                }

                $invoices[] = $invoice;
            }
        } finally {
            fclose($handle);
        }

        return ['invoices' => $invoices, 'skipped' => $skipped];
    }

    /**
     * Windows Excel in a Persian locale writes semicolons. Guessing from the
     * header line is enough and costs one read.
     */
    private function sniffDelimiter(string $path): string
    {
        $first = (string) fgets(fopen($path, 'r'), 8192);

        return substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, int>|null
     */
    private function mapHeaders(array $cells): ?array
    {
        $map = [];

        foreach ($cells as $index => $cell) {
            // Excel prefixes the very first header with a UTF-8 BOM, which
            // otherwise makes an exact match silently impossible.
            $header = PersianText::normalize(str_replace("\u{FEFF}", '', (string) $cell));

            foreach (self::COLUMNS as $field => $spellings) {
                foreach ($spellings as $spelling) {
                    if ($header === PersianText::normalize($spelling)) {
                        $map[$field] ??= $index;
                    }
                }
            }
        }

        // Without these three there is nothing to chase and nobody to chase
        // about it, so the file is rejected rather than half-imported.
        foreach (['customer_name', 'amount', 'due_on'] as $required) {
            if (! isset($map[$required])) {
                return null;
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $map
     * @return ImportedInvoice|string the invoice, or why the row was skipped
     */
    private function toInvoice(array $cells, array $map, int $rowNumber): ImportedInvoice|string
    {
        $read = fn (string $field): ?string => isset($map[$field]) && isset($cells[$map[$field]])
            ? (trim((string) $cells[$map[$field]]) ?: null)
            : null;

        $customer = $read('customer_name');
        $amount = ImportedInvoice::parseAmount($read('amount'));
        $dueOn = $this->toDate($read('due_on'));

        if ($customer === null) {
            return 'نام مشتری خالی است.';
        }

        if ($amount === null || $amount <= 0) {
            return 'مبلغ خوانده نشد.';
        }

        if ($dueOn === null) {
            return 'تاریخ سررسید خوانده نشد.';
        }

        $issuedOn = $this->toDate($read('issued_on')) ?? $dueOn;
        $settled = ImportedInvoice::parseAmount($read('settled')) ?? 0;

        return new ImportedInvoice(
            // Falling back to the row number keeps a file with no invoice
            // numbers importable, at the cost of re-importing it creating new
            // rows — which the import screen warns about.
            reference: $read('reference') ?? 'row-'.$rowNumber,
            customerName: $customer,
            customerPhone: ImportedInvoice::normalisePhone($read('customer_phone')),
            title: $read('title') ?? 'فاکتور فروش',
            amount: $amount,
            settledAmount: min($settled, $amount),
            issuedOn: $issuedOn,
            dueOn: $dueOn,
        );
    }

    /**
     * Accepts both calendars, because a single export often mixes them. A
     * four-digit year starting with 13 or 14 is Jalali; anything else is read
     * as Gregorian.
     */
    private function toDate(?string $raw): ?CarbonImmutable
    {
        if ($raw === null) {
            return null;
        }

        $value = PersianText::foldDigits(trim($raw));

        if (! preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/', $value, $m)) {
            return null;
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if ($year >= 1200 && $year <= 1600) {
            return JalaliDate::toGregorian($year, $month, $day);
        }

        try {
            return CarbonImmutable::create($year, $month, $day)?->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
