<?php

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureSubscriptionAllowsWrites;
use App\Http\Middleware\EnsureUserHasWorkspace;
use App\Http\Middleware\EnsureWorkspaceHasModule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'subscribed' => EnsureSubscriptionAllowsWrites::class,
            'module' => EnsureWorkspaceHasModule::class,
            'platform-admin' => EnsurePlatformAdmin::class,
            'has-workspace' => EnsureUserHasWorkspace::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
