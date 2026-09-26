<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Services\CurrentWorkspace;
use App\Services\GettingStarted;
use Illuminate\Http\RedirectResponse;

class DismissGettingStartedController extends Controller
{
    /**
     * Hide the dashboard checklist for the whole workspace. Only someone who
     * could act on it may hide it for everyone.
     */
    public function __invoke(CurrentWorkspace $current, GettingStarted $gettingStarted): RedirectResponse
    {
        abort_unless($current->can(Permission::ManageMembers), 403);

        $gettingStarted->dismiss($current->get());

        return redirect()->route('dashboard');
    }
}
