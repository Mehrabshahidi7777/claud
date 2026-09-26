<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Services\CurrentWorkspace;
use App\Services\Dashboard;
use App\Services\GettingStarted;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $workspace,
        private readonly Dashboard $dashboard,
        private readonly GettingStarted $gettingStarted,
    ) {}

    public function __invoke(Request $request)
    {
        $workspace = $this->workspace->get();

        return view('dashboard', [
            'workspace' => $workspace,
            'cards' => $this->dashboard->for($workspace, $request->user(), $this->workspace->role()),
            'week' => $this->dashboard->weekSoFar($workspace),

            // Only for whoever can add people: a member who was invited has
            // nothing to set up.
            'gettingStarted' => $this->workspace->can(Permission::ManageMembers)
                ? $this->gettingStarted->stepsFor($workspace)
                : null,
        ]);
    }
}
