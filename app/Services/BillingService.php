<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Plans, quotes and invoices.
 *
 * Every price is computed here from configuration and the seat count, never
 * taken from the request. A checkout form that could name its own amount is
 * a checkout form that gets a five million Rial plan for a thousand.
 */
class BillingService
{
    /**
     * Whether پیگیر charges at all. Everything that would ask for money, or
     * stop work for want of it, asks this first.
     */
    public function enabled(): bool
    {
        return (bool) config('payment.enabled');
    }

    /**
     * A new workspace's monthly SMS allowance, from the plan its type maps to.
     * Free or paid, every SMS is real money at the provider, so the allowance
     * is the one limit that stays.
     */
    public function assignSmsAllowance(Workspace $workspace, ?string $planKey = null): void
    {
        $planKey ??= $workspace->type->planKey();

        $workspace->forceFill([
            'sms_quota' => (int) config("payment.plans.$planKey.included_sms", $workspace->sms_quota),
            'sms_period_started_at' => $workspace->sms_period_started_at ?? now(),
        ])->save();
    }

    /**
     * What a term costs, in Rial, broken out the way an invoice needs it.
     *
     * @return array{plan_key: string, seats: int, term: string, subtotal: int, vat: int, total: int, vat_percent: int, months: int}
     */
    public function quote(string $planKey, int $seats, string $term = 'monthly'): array
    {
        $plan = config("payment.plans.$planKey");

        if ($plan === null) {
            throw new InvalidArgumentException("Unknown plan [$planKey].");
        }

        if (! in_array($term, ['monthly', 'yearly'], true)) {
            throw new InvalidArgumentException("Unknown term [$term].");
        }

        // Seats are clamped rather than trusted: below the minimum the plan is
        // not the plan, and above the maximum it is a different conversation.
        $seats = max($plan['min_seats'], min($seats, $plan['max_seats']));

        // A yearly term is charged for ten months. The two free months pull
        // cash forward and make a forgotten renewal twelve times less likely.
        $months = $term === 'yearly' ? (int) config('payment.yearly_months_charged', 10) : 1;

        $billableSeats = $plan['per_seat'] ? $seats : 1;
        $subtotal = $plan['price_per_seat'] * $billableSeats * $months;

        $vatPercent = (int) config('payment.vat_percent', 10);
        $vat = (int) round($subtotal * $vatPercent / 100);

        return [
            'plan_key' => $planKey,
            'seats' => $seats,
            'term' => $term,
            'months' => $months,
            'subtotal' => $subtotal,
            'vat' => $vat,
            'total' => $subtotal + $vat,
            'vat_percent' => $vatPercent,
        ];
    }

    /**
     * Raise an invoice for a term. The subscription is not touched — nothing
     * about it changes until money has actually been verified.
     *
     * @param  array<string, string|null>  $billingDetails
     */
    public function invoiceFor(
        Workspace $workspace,
        string $planKey,
        int $seats,
        string $term,
        array $billingDetails = [],
    ): Invoice {
        $quote = $this->quote($planKey, $seats, $term);
        $subscription = $this->currentSubscription($workspace);

        // A renewal extends from where the current term ends, not from today,
        // so paying early never costs the customer the days they already have.
        $periodStart = $subscription?->ends_at?->isFuture()
            ? $subscription->ends_at->copy()
            : now();

        $periodEnd = $periodStart->copy()->addMonths($term === 'yearly' ? 12 : 1);

        return DB::transaction(function () use ($workspace, $quote, $subscription, $periodStart, $periodEnd, $billingDetails) {
            return Invoice::create([
                'workspace_id' => $workspace->id,
                'subscription_id' => $subscription?->id,
                'number' => $this->nextInvoiceNumber(),
                'plan_key' => $quote['plan_key'],
                'seats' => $quote['seats'],
                'term' => $quote['term'],
                'subtotal' => $quote['subtotal'],
                'vat' => $quote['vat'],
                'total' => $quote['total'],
                'vat_percent' => $quote['vat_percent'],
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'status' => InvoiceStatus::Unpaid,
                'legal_name' => $billingDetails['legal_name'] ?? null,
                'national_id' => $billingDetails['national_id'] ?? null,
                'economic_code' => $billingDetails['economic_code'] ?? null,
                'address' => $billingDetails['address'] ?? null,
            ]);
        });
    }

    /**
     * What more places on the running company subscription cost: the plan's
     * per-person price for the term, for the share of the term that is left.
     * Buying the sixth place with three months to go costs three months of
     * one place, not a whole year.
     *
     * @return array{seats: int, days: int, subtotal: int, vat: int, total: int, vat_percent: int}
     */
    public function seatQuote(Workspace $workspace, int $extraSeats): array
    {
        $subscription = $this->currentSubscription($workspace);

        if ($subscription === null || $subscription->status === SubscriptionStatus::Trialing) {
            throw new InvalidArgumentException('Places are bought on a paid subscription.');
        }

        $plan = config("payment.plans.$subscription->plan_key");

        if (! ($plan['per_seat'] ?? false)) {
            throw new InvalidArgumentException("Plan [$subscription->plan_key] is not priced per person.");
        }

        $extraSeats = max(0, min($extraSeats, $plan['max_seats'] - $subscription->seats));

        $isYearly = $subscription->term === 'yearly';
        $termDays = $isYearly ? 365 : 30;
        $months = $isYearly ? (int) config('payment.yearly_months_charged', 10) : 1;
        // Not capped at one term: a customer who renewed early can have more
        // than a month left, and each of those days is a day the place is used.
        $daysLeft = (int) max(1, ceil(now()->diffInDays($subscription->ends_at, absolute: true)));

        // Rounded to whole Toman: an invoice that ends in a stray Rial reads
        // as a mistake.
        $subtotal = (int) (round($plan['price_per_seat'] * $months * $extraSeats * $daysLeft / $termDays / 10) * 10);

        $vatPercent = (int) config('payment.vat_percent', 10);
        $vat = (int) (round($subtotal * $vatPercent / 100 / 10) * 10);

        return [
            'seats' => $extraSeats,
            'days' => $daysLeft,
            'subtotal' => $subtotal,
            'vat' => $vat,
            'total' => $subtotal + $vat,
            'vat_percent' => $vatPercent,
        ];
    }

    /**
     * Raise an invoice for more places. Like any invoice, nothing changes
     * until the bank's verify agrees.
     */
    public function seatInvoiceFor(Workspace $workspace, int $extraSeats): Invoice
    {
        $quote = $this->seatQuote($workspace, $extraSeats);
        $subscription = $this->currentSubscription($workspace);

        if ($quote['seats'] < 1) {
            throw new InvalidArgumentException('No places left to buy on this plan.');
        }

        return DB::transaction(fn () => Invoice::create([
            'workspace_id' => $workspace->id,
            'subscription_id' => $subscription->id,
            'number' => $this->nextInvoiceNumber(),
            'plan_key' => $subscription->plan_key,
            'kind' => 'seats',
            'seats' => $quote['seats'],
            'term' => $subscription->term,
            'subtotal' => $quote['subtotal'],
            'vat' => $quote['vat'],
            'total' => $quote['total'],
            'vat_percent' => $quote['vat_percent'],
            'period_start' => now()->toDateString(),
            'period_end' => $subscription->ends_at->toDateString(),
            'status' => InvoiceStatus::Unpaid,
        ]));
    }

    /**
     * Apply a settled invoice: mark it paid and move the subscription out to
     * the period it covers.
     *
     * Called only after a payment has been verified server to server, and
     * written in one transaction so a crash between the two cannot leave an
     * invoice paid with nothing extended.
     */
    public function applyPaidInvoice(Invoice $invoice): Subscription
    {
        if ($invoice->kind === 'seats') {
            return $this->applyPaidSeats($invoice);
        }

        return DB::transaction(function () use ($invoice) {
            $invoice->update([
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
            ]);

            $subscription = $this->currentSubscription($invoice->workspace) ?? new Subscription([
                'workspace_id' => $invoice->workspace_id,
                'starts_at' => now(),
            ]);

            // Extending from the later of now and the existing end date means
            // an early renewal adds to the term rather than truncating it, and
            // a lapsed one restarts from today rather than from the past.
            $from = $subscription->exists && $subscription->ends_at?->isFuture()
                ? $subscription->ends_at->copy()
                : now();

            $subscription->fill([
                'workspace_id' => $invoice->workspace_id,
                'plan_key' => $invoice->plan_key,
                'seats' => $invoice->seats,
                'term' => $invoice->term,
                'status' => SubscriptionStatus::Active,
                'starts_at' => $subscription->starts_at ?? now(),
                'ends_at' => $from->addMonths($invoice->term === 'yearly' ? 12 : 1),
                'grace_ends_at' => null,
                'cancelled_at' => null,
                // A new term starts a new reminder cycle.
                'reminders_sent' => [],
            ])->save();

            $invoice->update(['subscription_id' => $subscription->id]);

            $this->applyPlanQuotas($invoice->workspace, $invoice->plan_key);

            return $subscription;
        });
    }

    /**
     * More places, paid for: added to the running term, whose end date does
     * not move. If the term lapsed between invoice and payment, the places
     * still go onto the workspace's latest subscription, so a renewal picks
     * up the count they paid for.
     */
    private function applyPaidSeats(Invoice $invoice): Subscription
    {
        return DB::transaction(function () use ($invoice) {
            $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);

            $subscription = $this->currentSubscription($invoice->workspace)
                ?? Subscription::where('workspace_id', $invoice->workspace_id)->latest('ends_at')->firstOrFail();

            $max = (int) config("payment.plans.$subscription->plan_key.max_seats", PHP_INT_MAX);
            $subscription->update(['seats' => min($max, $subscription->seats + $invoice->seats)]);

            $invoice->update(['subscription_id' => $subscription->id]);

            return $subscription;
        });
    }

    /**
     * The free trial, fifteen days unless configured otherwise. No card is
     * asked for: a trial that wants one is a trial most people never start.
     */
    public function startTrial(Workspace $workspace, ?string $planKey = null): Subscription
    {
        // A household on trial is trying the household plan. Defaulting every
        // trial to corporate showed a family a five-seat company invoice.
        $planKey ??= $workspace->type->planKey();

        // A trial sends what the plan it is trying would send, so the first
        // invoice holds no surprise about how many reminders go out.
        $this->assignSmsAllowance($workspace, $planKey);

        return Subscription::create([
            'workspace_id' => $workspace->id,
            'plan_key' => $planKey,
            'seats' => config("payment.plans.$planKey.min_seats", 1),
            'term' => 'monthly',
            'status' => SubscriptionStatus::Trialing,
            'starts_at' => now(),
            'ends_at' => now()->addDays((int) config('payment.trial_days', 15)),
        ]);
    }

    /**
     * Days given by the platform owner rather than bought: a longer trial for
     * a company that is still deciding, a week for an outage, a free month
     * for a first customer.
     *
     * Days are added to whatever is left, never to today, so a gift cannot
     * shorten a term. A subscription in grace or already gone comes back as
     * active; a trial stays a trial, just a longer one.
     */
    public function grantDays(Workspace $workspace, int $days): Subscription
    {
        return DB::transaction(function () use ($workspace, $days) {
            $subscription = $this->currentSubscription($workspace);

            if ($subscription === null) {
                return Subscription::create([
                    'workspace_id' => $workspace->id,
                    'plan_key' => $workspace->type->planKey(),
                    'seats' => config("payment.plans.{$workspace->type->planKey()}.min_seats", 1),
                    'term' => 'monthly',
                    'status' => SubscriptionStatus::Active,
                    'starts_at' => now(),
                    'ends_at' => now()->addDays($days),
                ]);
            }

            $from = $subscription->ends_at->isFuture() ? $subscription->ends_at->copy() : now();

            $subscription->update([
                'ends_at' => $from->addDays($days),
                'grace_ends_at' => null,
                'status' => $subscription->status === SubscriptionStatus::Trialing
                    ? SubscriptionStatus::Trialing
                    : SubscriptionStatus::Active,
            ]);

            return $subscription;
        });
    }

    public function currentSubscription(Workspace $workspace): ?Subscription
    {
        return Subscription::where('workspace_id', $workspace->id)
            ->whereIn('status', [
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Grace->value,
            ])
            ->latest('ends_at')
            ->first();
    }

    /**
     * The SMS allowance comes with the plan, so paying for a bigger one has to
     * raise it — otherwise the follow-up engine stays throttled on a plan the
     * customer has already paid more for.
     */
    private function applyPlanQuotas(Workspace $workspace, string $planKey): void
    {
        $included = (int) config("payment.plans.$planKey.included_sms", 0);

        // forceFill: the period start is not mass-assignable, and update()
        // would drop it without a word.
        $workspace->forceFill([
            'sms_quota' => $included,
            'sms_used' => 0,
            'sms_period_started_at' => now(),
        ])->save();
    }

    /**
     * Sequential within the Jalali year, which is how an Iranian accountant
     * expects to read them: 1405-0001.
     */
    private function nextInvoiceNumber(): string
    {
        [$year] = JalaliDate::fromGregorian(CarbonImmutable::now());

        $lastNumber = Invoice::where('number', 'like', $year.'-%')
            ->orderByDesc('id')
            ->value('number');

        $sequence = $lastNumber === null ? 1 : ((int) substr($lastNumber, 5)) + 1;

        return sprintf('%d-%04d', $year, $sequence);
    }
}
