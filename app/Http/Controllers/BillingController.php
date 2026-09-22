<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\BillingService;
use App\Services\CurrentWorkspace;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $workspace,
        private readonly BillingService $billing,
    ) {}

    public function index()
    {
        $workspace = $this->workspace->get();

        // Only an owner sees prices and invoices. A member who could raise an
        // invoice could commit the company to a bill.
        abort_unless($this->workspace->role()->canManageMembers(), 403);

        $subscription = $this->billing->currentSubscription($workspace);

        return view('billing.index', [
            'workspace' => $workspace,
            'subscription' => $subscription,
            'plans' => config('payment.plans'),
            'seats' => $workspace->members()->count(),
            'invoices' => Invoice::where('workspace_id', $workspace->id)
                ->latest('id')
                ->limit(12)
                ->get(),
        ]);
    }

    /**
     * Raise an invoice. Nothing is charged here and the subscription is not
     * touched — the price is recomputed from configuration and the seat count,
     * never read from the form.
     */
    public function store(Request $request)
    {
        $workspace = $this->workspace->get();

        abort_unless($this->workspace->role()->canManageMembers(), 403);

        $validated = $request->validate([
            'plan_key' => ['required', Rule::in(array_keys(config('payment.plans')))],
            'seats' => ['required', 'integer', 'min:1', 'max:500'],
            'term' => ['required', Rule::in(['monthly', 'yearly'])],
            'legal_name' => ['nullable', 'string', 'max:150'],
            'national_id' => ['nullable', 'string', 'max:20'],
            'economic_code' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $invoice = $this->billing->invoiceFor(
            $workspace,
            $validated['plan_key'],
            (int) $validated['seats'],
            $validated['term'],
            $validated,
        );

        return redirect()->route('billing.invoice', $invoice);
    }

    public function invoice(Invoice $invoice)
    {
        abort_unless($invoice->workspace_id === $this->workspace->get()->id, 404);
        abort_unless($this->workspace->role()->canManageMembers(), 403);

        return view('billing.invoice', [
            'invoice' => $invoice,
            'workspace' => $invoice->workspace,
        ]);
    }

    /**
     * Send the payer to the bank.
     */
    public function pay(Request $request, Invoice $invoice, PaymentService $payments)
    {
        abort_unless($invoice->workspace_id === $this->workspace->get()->id, 404);
        abort_unless($this->workspace->role()->canManageMembers(), 403);

        if (! $invoice->status->isPayable()) {
            return redirect()->route('billing.invoice', $invoice)
                ->with('status', 'این فاکتور قبلاً تسویه شده است.');
        }

        $token = $payments->start(
            $invoice,
            route('billing.callback'),
            $request->user()->phone,
        );

        if ($token === null) {
            return redirect()->route('billing.invoice', $invoice)
                ->withErrors(['payment' => 'ارتباط با درگاه برقرار نشد. چند دقیقه دیگر دوباره تلاش کنید.']);
        }

        return redirect()->away($token->redirectUrl);
    }
}
