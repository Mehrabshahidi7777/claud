<?php

namespace App\Http\Controllers;

use App\Models\WeeklyReport;
use App\Services\CurrentWorkspace;

class WeeklyReportController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $workspace) {}

    /**
     * A generated report at its permanent address.
     *
     * Reached by an unguessable token rather than an id, so the link an SMS
     * carries opens on whatever device the manager is holding. Being signed
     * in is still required — the token makes the link shareable, not the
     * report public, and it names who is behind on their work.
     */
    public function show(string $token)
    {
        $report = WeeklyReport::where('share_token', $token)->firstOrFail();

        // The token is not authorisation. Someone signed into another
        // workspace who came by this link gets a 404, the same answer as a
        // token that does not exist.
        abort_unless($report->workspace_id === $this->workspace->get()->id, 404);

        return view('reports.weekly', [
            'report' => $report,
            'workspace' => $report->workspace,
        ]);
    }

    /**
     * Past reports, newest first. The archive is where the trend lives: one
     * week's number means little, six weeks of them is the argument for
     * renewing.
     */
    public function index()
    {
        $workspace = $this->workspace->get();

        return view('reports.weekly-index', [
            'workspace' => $workspace,
            'reports' => WeeklyReport::where('workspace_id', $workspace->id)
                ->latest('period_start')
                ->paginate(20),
        ]);
    }
}
