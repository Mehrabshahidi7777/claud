<?php

namespace App\Http\Middleware;

use App\Services\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a module this workspace's plan does not include.
 *
 * A 404 rather than a 403, matching how the rest of the system answers for
 * anything outside your workspace: telling a family that a receivables page
 * exists and they are not allowed on it is worse than telling them nothing.
 * As far as a household is concerned, that page is not part of the product.
 *
 * Hiding the link is not enough on its own — a link nobody can see is still a
 * URL somebody eventually types, or lands on from a bookmark after their plan
 * changed.
 */
class EnsureWorkspaceHasModule
{
    public function __construct(private readonly CurrentWorkspace $workspace) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->workspace->get()->has($module), 404);

        return $next($request);
    }
}
