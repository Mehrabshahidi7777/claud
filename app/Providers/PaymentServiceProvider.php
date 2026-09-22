<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\SepGateway;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function ($app) {
            return match ($gateway = config('payment.gateway', 'fake')) {
                'sep' => new SepGateway($app->make(HttpFactory::class), config('payment.sep')),
                'fake' => new FakeGateway,

                // Unlike the SMS layer there is no safe middle ground here: a
                // misconfigured gateway must not quietly become a real one, or
                // quietly take money and lose it.
                default => throw new InvalidArgumentException("Unsupported payment gateway [$gateway]."),
            };
        });
    }
}
