<?php

namespace Tests\Feature;

use App\Accounting\ImportedInvoice;
use App\Accounting\SpreadsheetReader;
use App\Contracts\SmsDriver;
use App\Enums\ReceivableStatus;
use App\Enums\TaskStatus;
use App\Models\Receivable;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ReceivableChaser;
use App\Services\ReceivableImporter;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Importing the accountant's export.
 *
 * The rule everything here protects: uploading the same file twice must not
 * double the company's receivables. A company that sees every invoice twice
 * never trusts the figures again, and never trusts the product again either.
 */
class ReceivableImportTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsDriver::class, new FakeSmsDriver);
        CarbonImmutable::setTestNow('2026-09-24 09:00:00');

        $this->workspace = Workspace::factory()->create();
        $this->owner = User::factory()->create(['name' => 'مهراب شهیدی']);
        $this->member = User::factory()->create(['name' => 'رضا مرادی']);

        $this->workspace->members()->attach($this->owner, ['role' => 'owner']);
        $this->workspace->members()->attach($this->member, ['role' => 'member']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function csv(string $body, bool $bom = true): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv').'.csv';
        file_put_contents($path, ($bom ? "\u{FEFF}" : '').$body);

        return new UploadedFile($path, 'invoices.csv', 'text/csv', null, true);
    }

    private function upload(UploadedFile $file)
    {
        return $this->actingAs($this->owner)->post(route('finance.import.store'), ['file' => $file]);
    }

    public function test_it_imports_an_export_with_persian_headers_and_jalali_dates(): void
    {
        $this->upload($this->csv(<<<'CSV'
        شماره فاکتور,نام مشتری,شماره تماس,شرح,مبلغ,مبلغ دریافتی,تاریخ صدور,تاریخ سررسید
        1404-118,شرکت ساختمانی نگین,09121234567,صورت‌وضعیت شماره ۳,"1,240,000,000",0,1405/06/20,1405/07/20
        CSV))->assertRedirect();

        $receivable = Receivable::sole();

        $this->assertSame('شرکت ساختمانی نگین', $receivable->customer_name);
        $this->assertSame(1_240_000_000, $receivable->amount);
        $this->assertSame('989121234567', $receivable->customer_phone);
        $this->assertSame('1404-118', $receivable->external_ref);
        $this->assertSame(ReceivableStatus::Open, $receivable->status);

        // 1405/07/20 is 2026-10-12 — proof the Jalali column was converted
        // rather than stored as written.
        $this->assertSame('2026-10-12', $receivable->due_on->toDateString());
    }

    public function test_importing_the_same_file_twice_does_not_double_the_books(): void
    {
        $body = <<<'CSV'
        شماره فاکتور,نام مشتری,مبلغ,تاریخ سررسید
        1404-118,شرکت نگین,500000000,1405/07/20
        1404-119,مجتمع الهیه,300000000,1405/08/01
        CSV;

        $this->upload($this->csv($body))->assertRedirect();
        $this->upload($this->csv($body))->assertRedirect();

        $this->assertSame(2, Receivable::count());
    }

    public function test_a_re_import_never_unassigns_the_person_chasing_it(): void
    {
        // The accounting package is the authority on money. It is not the
        // authority on who inside the company is collecting the debt, and
        // tomorrow's upload must not undo a manager's assignment.
        $body = <<<'CSV'
        شماره فاکتور,نام مشتری,مبلغ,تاریخ سررسید
        1404-118,شرکت نگین,500000000,1405/07/20
        CSV;

        $this->upload($this->csv($body));

        Receivable::sole()->update(['owner_id' => $this->member->id]);

        $this->upload($this->csv($body));

        $this->assertSame($this->member->id, Receivable::sole()->owner_id);
    }

    public function test_a_payment_recorded_in_accounting_closes_the_chase_here(): void
    {
        $this->upload($this->csv(<<<'CSV'
        شماره فاکتور,نام مشتری,مبلغ,مبلغ دریافتی,تاریخ سررسید
        1404-118,شرکت نگین,500000000,0,1405/06/01
        CSV));

        app(ReceivableChaser::class)->sweepWorkspace($this->workspace);

        $task = Receivable::sole()->task;
        $this->assertNotNull($task);

        // Next morning's export shows the invoice paid.
        $this->upload($this->csv(<<<'CSV'
        شماره فاکتور,نام مشتری,مبلغ,مبلغ دریافتی,تاریخ سررسید
        1404-118,شرکت نگین,500000000,500000000,1405/06/01
        CSV));

        $this->assertSame(ReceivableStatus::Settled, Receivable::sole()->status);
        $this->assertSame(TaskStatus::Done, $task->refresh()->status);
    }

    public function test_a_stale_export_never_reopens_an_invoice_already_paid(): void
    {
        // An export taken before the payment would otherwise walk the settled
        // figure backwards and start chasing a customer who has paid.
        $this->upload($this->csv(<<<'CSV'
        شماره فاکتور,نام مشتری,مبلغ,مبلغ دریافتی,تاریخ سررسید
        1404-118,شرکت نگین,500000000,500000000,1405/06/01
        CSV));

        $this->upload($this->csv(<<<'CSV'
        شماره فاکتور,نام مشتری,مبلغ,مبلغ دریافتی,تاریخ سررسید
        1404-118,شرکت نگین,500000000,0,1405/06/01
        CSV));

        $receivable = Receivable::sole();

        $this->assertSame(ReceivableStatus::Settled, $receivable->status);
        $this->assertSame(500_000_000, $receivable->settled_amount);
    }

    public function test_a_semicolon_export_from_a_windows_excel_still_reads(): void
    {
        $this->upload($this->csv(<<<'CSV'
        نام مشتری;مبلغ;تاریخ سررسید
        شرکت نگین;500000000;1405/07/20
        CSV))->assertRedirect();

        $this->assertSame(1, Receivable::count());
    }

    public function test_a_file_missing_the_columns_that_matter_is_refused_whole(): void
    {
        // Half an import is worse than none: the company would believe the
        // figures were complete.
        $this->upload($this->csv(<<<'CSV'
        نام مشتری,توضیحات
        شرکت نگین,چیزی
        CSV))->assertSessionHasErrors('file');

        $this->assertSame(0, Receivable::count());
    }

    public function test_unreadable_rows_are_reported_rather_than_dropped_in_silence(): void
    {
        $this->upload($this->csv(<<<'CSV'
        شماره فاکتور,نام مشتری,مبلغ,تاریخ سررسید
        1404-118,شرکت نگین,500000000,1405/07/20
        1404-119,مجتمع الهیه,نامشخص,1405/08/01
        1404-120,,300000000,1405/08/05
        CSV))->assertRedirect();

        $this->assertSame(1, Receivable::count());

        $skipped = session('importSkipped');

        $this->assertCount(2, $skipped);
        $this->assertSame('مبلغ خوانده نشد.', $skipped[0]['reason']);
        $this->assertSame('نام مشتری خالی است.', $skipped[1]['reason']);
    }

    public function test_an_amount_written_the_way_people_write_amounts(): void
    {
        $reader = new SpreadsheetReader;

        $cases = [
            '۱,۲۴۰,۰۰۰,۰۰۰' => 1_240_000_000,
            '1240000000.00' => 1_240_000_000,
            '1 240 000' => 1_240_000,
            '500000 ریال' => 500_000,
            'نامشخص' => null,
        ];

        foreach ($cases as $written => $expected) {
            $this->assertSame(
                $expected,
                ImportedInvoice::parseAmount((string) $written),
                "Failed reading [$written].",
            );
        }

        $this->assertInstanceOf(SpreadsheetReader::class, $reader);
    }

    public function test_the_import_page_is_closed_to_ordinary_members(): void
    {
        $this->actingAs($this->member)->get(route('finance.import'))->assertForbidden();
        $this->actingAs($this->member)->get(route('finance.import.template'))->assertForbidden();
    }

    public function test_the_template_downloads_with_a_bom_excel_can_read(): void
    {
        $response = $this->actingAs($this->owner)->get(route('finance.import.template'));

        $response->assertOk();

        // Without the BOM, Excel opens the Persian headers as mojibake and the
        // customer concludes the whole feature is broken.
        $this->assertStringStartsWith("\u{FEFF}", $response->streamedContent());
        $this->assertStringContainsString('نام مشتری', $response->streamedContent());
    }

    public function test_importing_reports_what_it_actually_did(): void
    {
        $importer = app(ReceivableImporter::class);
        $reader = new SpreadsheetReader;

        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, <<<'CSV'
        شماره فاکتور,نام مشتری,مبلغ,تاریخ سررسید
        1404-118,شرکت نگین,500000000,1405/07/20
        CSV);

        $first = $importer->import($this->workspace, $reader->read($path)['invoices'], $this->owner, 'spreadsheet');
        $second = $importer->import($this->workspace, $reader->read($path)['invoices'], $this->owner, 'spreadsheet');

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['unchanged']);
    }
}
