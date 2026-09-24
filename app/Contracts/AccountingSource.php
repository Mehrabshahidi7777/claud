<?php

namespace App\Contracts;

use App\Accounting\ImportedInvoice;
use App\Models\Workspace;

/**
 * The seam between this system and whichever accounting package the customer
 * already owns — Sepidar, Holoo, Rafe, or an accountant with a spreadsheet.
 *
 * One rule shapes this whole interface: it only ever reads. Nothing here can
 * write into the customer's books, and that is deliberate. The day our
 * software moves a figure in their ledger is the day an accountant says "your
 * software corrupted our accounts", and that sentence is not defensible even
 * when it is untrue.
 *
 * What we take is narrow: sales invoices and what has been received against
 * them. Not the chart of accounts, not journal entries, not tax. We are not a
 * competitor to their accounting package — we are the part of it that picks
 * up the phone.
 */
interface AccountingSource
{
    /**
     * Sales invoices to mirror as receivables.
     *
     * @return list<ImportedInvoice>
     */
    public function pull(Workspace $workspace): array;

    /** Whether this source has everything it needs to be asked. */
    public function isConfigured(): bool;

    /** The short key stored on each imported row, e.g. "spreadsheet". */
    public function key(): string;

    public function label(): string;
}
