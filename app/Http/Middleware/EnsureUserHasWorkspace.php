<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone signed in with no workspace yet is sent somewhere useful instead of
 * a bare "No workspace" error: the platform owner to their panel, everyone
 * else to finish signing up.
 */
class EnsureUserHasWorkspace
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->workspaces()->doesntExist()) {
            return redirect()->route($user->isPlatformAdmin() ? 'admin.dashboard' : 'onboarding');
        }

        return $next($request);
    }
}
