<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Services\BillingService;
use App\Services\CurrentWorkspace;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Relative times ("۲ ساعت دیگر") appear next to every task, saying
        // what the engine will do and when. Left unlocalised they render in
        // English in the middle of a Persian sentence.
        Carbon::setLocale('fa');
        CarbonImmutable::setLocale('fa');

        // Lazy loading is convenient until a list of fifty tasks issues fifty
        // queries for its assignees. Failing loudly in development is how that
        // gets caught before a customer's data makes it slow.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Whether the app is read-only, shared with every page. A one-shot
        // flash is not enough here: someone bounced from a save needs to know
        // why on whichever page they land on next, and the state persists
        // until they pay.
        View::composer('layouts.app', function ($view) {
            $view->with('subscriptionLapsed', $this->subscriptionHasLapsed());
            $view->with('financeVisible', $this->canSeeFinance());
            $view->with('reportsVisible', $this->canSeeReports());
            $view->with('allowed', $this->permissionCheck());
        });
    }

    /**
     * Whether to offer the money pages in the header at all.
     *
     * A link that answers 403 is worse than no link: it tells an ordinary
     * member the page exists and that they are not trusted with it, every
     * time they look at the navigation.
     */
    private function canSeeFinance(): bool
    {
        if (! Auth::check()) {
            return false;
        }

        try {
            return app(CurrentWorkspace::class)->role()->canSeeFinance();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The same question the controllers ask, for deciding which header links
     * to draw at all.
     *
     * @return Closure(Permission): bool
     */
    private function permissionCheck(): Closure
    {
        return function (Permission $permission): bool {
            if (! Auth::check()) {
                return false;
            }

            try {
                return app(CurrentWorkspace::class)->can($permission);
            } catch (Throwable) {
                return false;
            }
        };
    }

    private function canSeeReports(): bool
    {
        if (! Auth::check()) {
            return false;
        }

        try {
            return app(CurrentWorkspace::class)->seesAllTasks();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * True when the signed-in user's workspace can no longer be written to.
     * Anything unresolvable — signed out, mid-onboarding, no workspace yet —
     * is not a lapse, and must not put a scary banner on the sign-up screen.
     */
    private function subscriptionHasLapsed(): bool
    {
        if (! Auth::check()) {
            return false;
        }

        try {
            $workspace = app(CurrentWorkspace::class)->get();
        } catch (Throwable) {
            return false;
        }

        return ! app(BillingService::class)->currentSubscription($workspace)?->grantsAccess();
    }
}
