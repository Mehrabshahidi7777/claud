<?php

namespace App\Providers;

use App\Ai\Providers\NullAiProvider;
use App\Ai\Providers\OllamaProvider;
use App\Contracts\AiProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiProvider::class, function ($app) {
            return match (config('ai.provider', 'null')) {
                'ollama' => new OllamaProvider($app->make(HttpFactory::class), config('ai.ollama')),

                // Anything unrecognised falls back to the null object rather
                // than throwing. A misconfigured model must not be able to
                // take the whole application down.
                default => new NullAiProvider,
            };
        });
    }
}
