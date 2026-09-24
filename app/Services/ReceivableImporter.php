<?php

namespace App\Services;

use App\Accounting\ImportedInvoice;
use App\Enums\ReceivableStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Receivable;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Writes imported invoices into receivables, and is the only place that does.
 *
 * Every source — a spreadsheet today, an accounting package's web service
 * later — funnels through here, so the rules about what an import may and may
 * not overwrite are written once.
 */
class ReceivableImporter
{
    /**
     * @param  iterable<ImportedInvoice>  $invoices
     * @return array{created: int, updated: int, settled: int, unchanged: int}
     */
    public function import(Workspace $workspace, iterable $invoices, User $actor, string $source): array
    {
        $result = ['created' => 0, 'updated' => 0, 'settled' => 0, 'unchanged' => 0];

        foreach ($invoices as $invoice) {
            $outcome = DB::transaction(
                fn () => $this->upsert($workspace, $invoice, $actor, $source),
            );

            $result[$outcome]++;
        }

        Activity::record($workspace, 'receivables.imported', $workspace->id, $actor->id, $result);

        return $result;
    }

    private function upsert(Workspace $workspace, ImportedInvoice $invoice, User $actor, string $source): string
    {
        $existing = Receivable::forWorkspace($workspace->id)
            ->where('source', $source)
            ->where('external_ref', $invoice->reference)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            $this->create($workspace, $invoice, $actor, $source);

            return 'created';
        }

        return $this->refresh($existing, $invoice);
    }

    private function create(Workspace $workspace, ImportedInvoice $invoice, User $actor, string $source): void
    {
        Receivable::create([
            'workspace_id' => $workspace->id,
            'created_by' => $actor->id,

            // Nobody is named as collector by an import. The chase falls to
            // the workspace owner until a person is put on it, which is
            // better than falling to nobody.
            'owner_id' => null,

            'customer_name' => $invoice->customerName,
            'customer_phone' => $invoice->customerPhone,
            'title' => $invoice->title,
            'amount' => $invoice->amount,
            'settled_amount' => $invoice->settledAmount,
            'issued_on' => $invoice->issuedOn->toDateString(),
            'due_on' => $invoice->dueOn->toDateString(),
            'status' => $invoice->settledAmount >= $invoice->amount
                ? ReceivableStatus::Settled
                : ($invoice->settledAmount > 0 ? ReceivableStatus::Partial : ReceivableStatus::Open),
            'source' => $source,
            'external_ref' => $invoice->reference,
        ]);
    }

    /**
     * A re-import brings money news and nothing else.
     *
     * The accounting package is the authority on what has been received, so a
     * larger settled figure is taken. It is not the authority on who inside
     * the company is chasing the debt — so `owner_id` is never touched, and a
     * manager's assignment is not undone by tomorrow's upload.
     *
     * A settled figure that went *down* is ignored: that is an export from
     * before the payment, and letting it reopen a settled invoice would start
     * chasing a customer who has already paid.
     */
    private function refresh(Receivable $receivable, ImportedInvoice $invoice): string
    {
        $settled = max($receivable->settled_amount, min($invoice->settledAmount, $invoice->amount));

        $changes = array_filter([
            'amount' => $invoice->amount !== $receivable->amount ? $invoice->amount : null,
            'due_on' => $invoice->dueOn->toDateString() !== $receivable->due_on->toDateString()
                ? $invoice->dueOn->toDateString()
                : null,
            'settled_amount' => $settled !== $receivable->settled_amount ? $settled : null,
        ], fn ($value) => $value !== null);

        if ($changes === []) {
            return 'unchanged';
        }

        $nowSettled = $settled >= $invoice->amount;

        $receivable->update($changes + [
            'status' => $nowSettled
                ? ReceivableStatus::Settled
                : ($settled > 0 ? ReceivableStatus::Partial : $receivable->status),
        ]);

        // Paid in full ends the chase, exactly as recording the payment by
        // hand would. Without this the engine keeps texting someone about an
        // invoice the accountant has already closed.
        if ($nowSettled && $receivable->task !== null && ! $receivable->task->status->isClosed()) {
            $receivable->task->update(['status' => TaskStatus::Done, 'completed_at' => now()]);
            $receivable->task->followUps()->where('status', 'pending')->delete();
        }

        return $nowSettled ? 'settled' : 'updated';
    }
}
