<?php

namespace App\Accounting\Sources;

use App\Contracts\AccountingSource;
use App\Models\Workspace;
use RuntimeException;

/**
 * Sepidar, the accounting package most Iranian SMEs already run.
 *
 * NOT IMPLEMENTED, and deliberately left that way until a paying customer
 * asks for it. What is here is the shape it will take, so that wiring it up
 * is a matter of filling one method rather than rearranging the system.
 *
 * Before implementing, three things have to come from the customer's own
 * Sepidar licence — they differ by version and edition, and guessing them
 * from the outside produces a driver that works on nobody's installation:
 *
 *   1. The web service base address on their server, and whether the service
 *      is even enabled on their licence.
 *   2. The device registration and authentication scheme, and the credentials
 *      it issues.
 *   3. The exact response shape for sales invoices and receipts.
 *
 * The one thing already settled is the direction: this reads and never
 * writes. The day our software moves a figure in a customer's ledger is the
 * day an accountant says we corrupted their books, and that sentence is not
 * defensible even when it is untrue.
 */
class SepidarSource implements AccountingSource
{
    public function pull(Workspace $workspace): array
    {
        throw new RuntimeException(
            'Sepidar integration is not built yet. Import the invoice export as CSV instead.',
        );
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function key(): string
    {
        return 'sepidar';
    }

    public function label(): string
    {
        return 'سپیدار';
    }
}
