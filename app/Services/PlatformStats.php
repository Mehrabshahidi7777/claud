<?php

namespace App\Services;

use App\Contracts\SmsDriver;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SmsOutbound;
use App\Models\Subscription;
use App\Models\TaskFollowUp;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The numbers the person selling پیگیر looks at every morning: who signed up,
 * who is about to stop paying, what came in, and whether the engine is
 * actually running for everybody.
 */
class PlatformStats
{
    private const GRANTING = [
        SubscriptionStatus::Trialing->value,
        SubscriptionStatus::Active->value,
        SubscriptionStatus::Grace->value,
    ];

    public function __construct(private readonly BillingService $billing) {}

    /**
     * @return array{total: int, users: int, new: int, byType: array<string, int>}
     */
    public function customers(): array
    {
        $byType = Workspace::query()
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            'total' => Workspace::count(),
            'users' => User::count(),
            'new' => Workspace::where('created_at', '>=', now()->subDays(30))->count(),
            'byType' => collect(WorkspaceType::cases())
                ->mapWithKeys(fn (WorkspaceType $type) => [$type->label() => (int) ($byType[$type->value] ?? 0)])
                ->all(),
        ];
    }

    /**
     * @return array{trialing: int, active: int, grace: int, lapsed: int}
     */
    public function subscriptions(): array
    {
        $counts = Subscription::whereIn('status', self::GRANTING)
            ->selectRaw('status, COUNT(DISTINCT workspace_id) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'trialing' => (int) ($counts[SubscriptionStatus::Trialing->value] ?? 0),
            'active' => (int) ($counts[SubscriptionStatus::Active->value] ?? 0),
            'grace' => (int) ($counts[SubscriptionStatus::Grace->value] ?? 0),
            'lapsed' => Workspace::whereDoesntHave(
                'subscriptions',
                fn ($query) => $query->whereIn('status', self::GRANTING),
            )->count(),
        ];
    }

    /**
     * Money actually verified, in Rial and including VAT, beside the monthly
     * recurring figure the paying subscriptions add up to before VAT.
     *
     * @return array{last30: int, previous30: int, total: int, mrr: int}
     */
    public function revenue(): array
    {
        $paid = fn () => Invoice::where('status', InvoiceStatus::Paid->value);

        $mrr = Subscription::where('status', SubscriptionStatus::Active->value)
            ->get()
            ->sum(function (Subscription $subscription) {
                try {
                    $quote = $this->billing->quote($subscription->plan_key, $subscription->seats, $subscription->term);
                } catch (InvalidArgumentException) {
                    return 0;
                }

                return $subscription->term === 'yearly'
                    ? intdiv($quote['subtotal'], 12)
                    : $quote['subtotal'];
            });

        return [
            'last30' => (int) $paid()->where('paid_at', '>=', now()->subDays(30))->sum('total'),
            'previous30' => (int) $paid()->whereBetween('paid_at', [now()->subDays(60), now()->subDays(30)])->sum('total'),
            'total' => (int) $paid()->sum('total'),
            'mrr' => (int) $mrr,
        ];
    }

    /**
     * Trials about to end — the list to phone today, while they still care.
     *
     * @return Collection<int, Subscription>
     */
    public function trialsEndingSoon(int $days = 7): Collection
    {
        return $this->ending(SubscriptionStatus::Trialing, $days);
    }

    /**
     * @return Collection<int, Subscription>
     */
    public function renewalsDue(int $days = 14): Collection
    {
        return Subscription::whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Grace->value])
            ->where('ends_at', '<=', now()->addDays($days))
            ->with('workspace.owners')
            ->orderBy('ends_at')
            ->limit(20)
            ->get();
    }

    /**
     * @return array{sentSegments: int, failed: int, credit: ?int}
     */
    public function sms(): array
    {
        // The panel's balance is an HTTP call to the provider. It is cached
        // for five minutes, and a failure is cached too, so an unreachable
        // panel costs one slow page rather than every page.
        $credit = Cache::remember('platform:sms-credit', 300, function () {
            try {
                return app(SmsDriver::class)->credit() ?? false;
            } catch (Throwable) {
                return false;
            }
        });

        return [
            'sentSegments' => (int) SmsOutbound::where('status', 'sent')
                ->where('created_at', '>=', now()->subDays(30))
                ->sum('segments'),
            'failed' => SmsOutbound::where('status', 'failed')
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
            'credit' => $credit === false ? null : (int) $credit,
        ];
    }

    /**
     * Signs that something is broken for everybody at once.
     *
     * @return array{stuckFollowUps: int, failedJobs: int, unverifiedPayments: int}
     */
    public function health(): array
    {
        return [
            // The sweep runs every five minutes. A rung that has been due for
            // twenty means cron is not running on the server.
            'stuckFollowUps' => TaskFollowUp::due()
                ->where('scheduled_at', '<', now()->subMinutes(20))
                ->count(),
            'failedJobs' => DB::table('failed_jobs')->count(),

            // The bank took the money but the verify never came back. Each of
            // these is a customer who paid and may not have been credited.
            'unverifiedPayments' => Payment::where('status', PaymentStatus::Paid->value)->count(),
        ];
    }

    /**
     * @return Collection<int, Subscription>
     */
    private function ending(SubscriptionStatus $status, int $days): Collection
    {
        return Subscription::where('status', $status->value)
            ->whereBetween('ends_at', [now(), now()->addDays($days)])
            ->with('workspace.owners')
            ->orderBy('ends_at')
            ->limit(20)
            ->get();
    }
}
