<?php

namespace App\Http\Controllers;

use App\Accounting\SpreadsheetReader;
use App\Services\CurrentWorkspace;
use App\Services\ReceivableImporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bringing invoices in from whatever the company already uses.
 *
 * A spreadsheet rather than an API, because it works on day one against every
 * accounting package in the country and depends on no vendor. The seam for a
 * real integration is already in place (App\Contracts\AccountingSource) — it
 * stays unbuilt until a customer asks for a specific package, which is a
 * commercial decision rather than a technical one.
 */
class ReceivableImportController extends Controller
{
    private const SOURCE = 'spreadsheet';

    public function __construct(private readonly CurrentWorkspace $workspace) {}

    public function show()
    {
        return view('finance.import', [
            'workspace' => $this->guard(),
            'result' => session('importResult'),
            'skipped' => session('importSkipped', []),
        ]);
    }

    /**
     * A template with the headers this reader knows and one filled row, so
     * nobody has to guess the column names from a help page.
     */
    public function template(): StreamedResponse
    {
        $this->guard();

        $rows = [
            ['شماره فاکتور', 'نام مشتری', 'شماره تماس', 'شرح', 'مبلغ', 'مبلغ دریافتی', 'تاریخ صدور', 'تاریخ سررسید'],
            ['1404-118', 'شرکت ساختمانی نگین', '09121234567', 'صورت‌وضعیت شماره ۳', '1240000000', '0', '1405/06/20', '1405/07/20'],
        ];

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            // Excel reads a UTF-8 CSV as mojibake unless it finds a BOM, and
            // a template that opens as gibberish teaches the customer the
            // whole feature is broken.
            fwrite($handle, "\u{FEFF}");

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, 'receivables-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request, SpreadsheetReader $reader, ReceivableImporter $importer)
    {
        $workspace = $this->guard();

        $request->validate([
            'file' => ['required', 'file', 'max:4096', 'mimetypes:text/plain,text/csv,application/csv'],
        ], [
            'file.mimetypes' => 'فایل باید CSV باشد. در اکسل: ذخیره به‌صورت CSV UTF-8.',
        ]);

        $read = $reader->read($request->file('file')->getRealPath());

        if ($read['invoices'] === []) {
            return back()
                ->withErrors(['file' => $read['skipped'][0]['reason'] ?? 'هیچ ردیف قابل خواندنی پیدا نشد.'])
                ->with('importSkipped', $read['skipped']);
        }

        $result = $importer->import($workspace, $read['invoices'], $request->user(), self::SOURCE);

        return back()
            ->with('importResult', $result)
            ->with('importSkipped', $read['skipped']);
    }

    private function guard()
    {
        abort_unless($this->workspace->role()->canSeeFinance(), 403);

        return $this->workspace->get();
    }
}
