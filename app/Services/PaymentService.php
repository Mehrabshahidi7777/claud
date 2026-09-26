<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Activity;
use App\Models\Invoice;
use App\Models\Payment;
use App\Payments\PaymentRequest;
use App\Payments\TokenResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Starting a payment and settling one.
 *
 * The rule the whole class is built around: the callback is not proof. It
 * arrives through the payer's own browser carrying whatever that browser was
 * handed, so nothing is marked paid until the server-to-server verify agrees
 * — on the reference and on the amount.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly BillingService $billing,
        private readonly ReferralService $referrals,
    ) {}

    /**
     * Open a payment against an invoice and get the redirect for it.
     * Returns null when the bank refuses, so the caller can show the invoice
     * again rather than sending the payer into a broken redirect.
     */
    public function start(Invoice $invoice, string $callbackUrl, ?string $payerPhone = null): ?TokenResult
    {
        if (! $invoice->status->isPayable()) {
            return null;
        }

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'workspace_id' => $invoice->workspace_id,
            'gateway' => config('payment.gateway'),
            // The amount comes off the invoice, which was computed from
            // configuration. Nothing the payer sent reaches this line.
            'amount' => $invoice->total,
            'res_num' => $this->reference($invoice),
            'status' => PaymentStatus::Pending,
        ]);

        $token = $this->gateway->requestToken(new PaymentRequest(
            amount: $payment->amount,
            reference: $payment->res_num,
            callbackUrl: $callbackUrl,
            payerPhone: $payerPhone,
        ));

        if ($token === null) {
            $payment->update([
                'status' => PaymentStatus::Failed,
                'failure_reason' => 'gateway refused to issue a token',
            ]);

            return null;
        }

        $payment->update(['token' => $token->token]);

        return $token;
    }

    /**
     * Handle the payer's return.
     *
     * @param  array<string, mixed>  $callback
     * @return array{payment: ?Payment, settled: bool, reason: ?string}
     */
    public function settle(array $callback): array
    {
        $reference = $this->pick($callback, ['ResNum', 'resNum', 'res_num']);
        $bankReference = $this->pick($callback, ['RefNum', 'refNum', 'ref_num']);
        $state = $this->pick($callback, ['State', 'state', 'Status', 'status']);

        if ($reference === null) {
            return $this->outcome(null, false, 'missing_reference');
        }

        $payment = Payment::where('res_num', $reference)->first();

        // A reference we never issued. Someone is probing, or a callback from
        // another terminal has been misrouted; either way it is not ours.
        if ($payment === null) {
            Log::warning('Payment callback for an unknown reference', ['reference' => $reference]);

            return $this->outcome(null, false, 'unknown_reference');
        }

        // Already settled. Banks redeliver callbacks and payers refresh the
        // return page; neither may extend a subscription twice.
        if ($payment->status->isSettled()) {
            return $this->outcome($payment, true, 'already_settled');
        }

        $payment->update(['callback_payload' => $callback]);

        if ($bankReference === null || ! $this->stateIsSuccessful($state)) {
            $payment->update([
                'status' => PaymentStatus::Failed,
                'failure_reason' => 'gateway reported: '.($state ?? 'no state'),
            ]);

            return $this->outcome($payment, false, 'gateway_declined');
        }

        // A reference that already settled a different payment is a replay.
        // The unique index is the real guard; this check just answers politely
        // before the database has to.
        if (Payment::where('ref_num', $bankReference)->whereKeyNot($payment->id)->exists()) {
            Log::critical('Payment callback replayed a bank reference', [
                'reference' => $bankReference,
                'payment' => $payment->id,
            ]);

            $payment->update([
                'status' => PaymentStatus::Failed,
                'failure_reason' => 'bank reference already used',
            ]);

            return $this->outcome($payment, false, 'replayed_reference');
        }

        try {
            $payment->update([
                'status' => PaymentStatus::Paid,
                'ref_num' => $bankReference,
                'trace_no' => $this->pick($callback, ['TraceNo', 'traceNo']),
                'rrn' => $this->pick($callback, ['RRN', 'Rrn', 'rrn']),
                'card_masked' => $this->pick($callback, ['SecurePan', 'securePan', 'HashedCardNumber']),
                'paid_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->outcome($payment, false, 'replayed_reference');
        }

        return $this->verifyAndApply($payment);
    }

    /**
     * The step that decides. Everything before this is a claim.
     *
     * @return array{payment: Payment, settled: bool, reason: ?string}
     */
    public function verifyAndApply(Payment $payment): array
    {
        $result = $this->gateway->verify($payment->ref_num, $payment->amount);

        $payment->update(['verify_payload' => $result->raw ?: null]);

        if (! $result->successful) {
            // Unreachable is not "not paid" — the money may well have moved —
            // so the payment is left in `paid` for a later retry rather than
            // written off. Anything else the gateway actively refused is a
            // failure now.
            $unreachable = str_starts_with((string) $result->reason, 'unreachable');

            $payment->update([
                'status' => $unreachable ? PaymentStatus::Paid : PaymentStatus::Failed,
                'failure_reason' => $result->reason,
            ]);

            return $this->outcome($payment, false, $unreachable ? 'verification_pending' : 'verification_failed');
        }

        // Both sides in one transaction: a crash between them would leave an
        // invoice paid with nothing extended, or the reverse.
        //
        // The invoice row is locked before its status is read. The callback
        // arrives through the payer's browser, so it can be fired twice at
        // once; without the lock both requests saw "unpaid" and each added a
        // month to the subscription.
        DB::transaction(function () use ($payment) {
            $invoice = Invoice::whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();

            $payment->update([
                'status' => PaymentStatus::Verified,
                'verified_at' => now(),
            ]);

            if ($invoice->status !== InvoiceStatus::Paid) {
                $this->billing->applyPaidInvoice($invoice);

                // A first payment is what the referrer was promised days for.
                $this->referrals->rewardReferrerOf($invoice->workspace);
            }
        });

        Activity::record($payment->invoice, 'invoice.paid', $payment->workspace_id, properties: [
            'amount' => $payment->amount,
            'reference' => $payment->ref_num,
        ]);

        return $this->outcome($payment->fresh(), true, null);
    }

    /**
     * Unique per attempt, not per invoice: a payer who abandons the gateway
     * and comes back must get a fresh reference, because the bank remembers
     * the old one.
     */
    private function reference(Invoice $invoice): string
    {
        return $invoice->number.'-'.Str::lower(Str::random(8));
    }

    private function stateIsSuccessful(?string $state): bool
    {
        return in_array(Str::lower((string) $state), ['ok', 'success', '1', 'succeed'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function pick(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (filled($payload[$key] ?? null)) {
                return (string) $payload[$key];
            }
        }

        return null;
    }

    /**
     * @return array{payment: ?Payment, settled: bool, reason: ?string}
     */
    private function outcome(?Payment $payment, bool $settled, ?string $reason): array
    {
        return ['payment' => $payment, 'settled' => $settled, 'reason' => $reason];
    }
}
