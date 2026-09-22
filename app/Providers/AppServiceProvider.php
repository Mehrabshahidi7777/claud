<?php

namespace App\Providers;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

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
    }
}
