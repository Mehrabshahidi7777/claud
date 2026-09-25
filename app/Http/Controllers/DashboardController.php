<?php

namespace App\Http\Controllers;

use App\Services\CurrentWorkspace;
use App\Services\Dashboard;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $workspace,
        private readonly Dashboard $dashboard,
    ) {}

    public function __invoke(Request $request)
    {
        $workspace = $this->workspace->get();

        return view('dashboard', [
            'workspace' => $workspace,
            'cards' => $this->dashboard->for($workspace, $request->user(), $this->workspace->role()),
            'week' => $this->dashboard->weekSoFar($workspace),
        ]);
    }
}
