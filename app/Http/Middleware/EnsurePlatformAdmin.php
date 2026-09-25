<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform owner's panel. Anyone else gets a 404 rather than a 403: a
 * customer has no reason to learn that a page listing every company exists.
 */
class EnsurePlatformAdmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isPlatformAdmin(), 404);

        return $next($request);
    }
}
