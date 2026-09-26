<?php

namespace App\Http\Middleware;

use App\Services\BillingService;
use App\Services\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An expired workspace becomes read-only rather than locked.
 *
 * Locking a customer out of their own data makes them angry, not solvent —
 * they ring to complain instead of to pay. Letting them read everything and
 * change nothing makes the expiry obvious without taking hostages, and leaves
 * the one page that fixes it fully working.
 */
class EnsureSubscriptionAllowsWrites
{
    public function __construct(
        private readonly CurrentWorkspace $workspace,
        private readonly BillingService $billing,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Reading is always allowed, and so is anything under billing — the
        // page that takes the payment cannot be behind the paywall.
        if (! $this->billing->enabled() || $request->isMethod('GET') || $request->routeIs('billing.*', 'logout')) {
            return $next($request);
        }

        $subscription = $this->billing->currentSubscription($this->workspace->get());

        if ($subscription?->grantsAccess()) {
            return $next($request);
        }

        return redirect()
            ->route('billing.index')
            ->withErrors([
                'subscription' => 'اشتراک شما تمام شده است. تا تمدید، امکان ثبت تغییرات وجود ندارد.',
            ]);
    }
}
