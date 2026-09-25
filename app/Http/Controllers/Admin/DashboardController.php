<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PlatformStats;
use Illuminate\View\View;

/**
 * The platform owner's morning page.
 */
class DashboardController extends Controller
{
    public function __invoke(PlatformStats $stats): View
    {
        return view('admin.dashboard', [
            'customers' => $stats->customers(),
            'subscriptions' => $stats->subscriptions(),
            'revenue' => $stats->revenue(),
            'trialsEnding' => $stats->trialsEndingSoon(),
            'renewalsDue' => $stats->renewalsDue(),
            'sms' => $stats->sms(),
            'health' => $stats->health(),
        ]);
    }
}
