<?php

namespace App\Http\Controllers;

use App\Services\CurrentWorkspace;
use App\Services\ReportBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $workspace) {}

    public function index(Request $request)
    {
        $workspace = $this->workspace->get();

        $days = min(max($request->integer('days', 7), 1), 90);

        $report = (new ReportBuilder($workspace))
            ->weekly(CarbonImmutable::now()->subDays($days));

        return view('reports.index', [
            'workspace' => $workspace,
            'report' => $report,
            'days' => $days,
        ]);
    }
}
