<?php

namespace App\Http\Controllers;

use App\Services\CurrentWorkspace;
use Illuminate\Http\Request;

/**
 * Moving between the workspaces somebody belongs to.
 *
 * Outside the module gate on purpose: the whole point of switching is to get
 * from a household that has no finance page to a company that does, and a
 * middleware checking the workspace you are leaving would refuse exactly the
 * move you asked for.
 */
class WorkspaceSwitchController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $workspace) {}

    public function __invoke(Request $request, int $workspace)
    {
        abort_unless($this->workspace->switchTo($workspace), 404);

        return redirect()->route('dashboard');
    }
}
