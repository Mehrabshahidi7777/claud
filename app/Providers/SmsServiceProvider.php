<?php

namespace App\Providers;

use App\Contracts\SmsDriver;
use App\Sms\Drivers\AmootSmsDriver;
use App\Sms\Drivers\FakeSmsDriver;
use App\Sms\Drivers\LogSmsDriver;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class SmsServiceProvider extends ServiceProvider
{
    /**
     * Bound as a singleton so a test can swap in the fake once and have every
     * service resolved afterwards share it.
     */
    public function register(): void
    {
        $this->app->singleton(SmsDriver::class, function ($app) {
            return match ($driver = config('sms.driver', 'log')) {
                'amoot' => new AmootSmsDriver($app->make(HttpFactory::class), config('sms.amoot')),
                'log' => new LogSmsDriver($app->make(LogManager::class)),
                'fake' => new FakeSmsDriver,
                default => throw new InvalidArgumentException("Unsupported SMS driver [$driver]."),
            };
        });
    }
}
