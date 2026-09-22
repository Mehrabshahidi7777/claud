<?php

namespace App\Http\Controllers;

use App\Services\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $workspace) {}

    public function index(Request $request): View
    {
        $workspace = $this->workspace->get();

        return view('notifications.index', [
            'workspace' => $workspace,

            // Scoped to the current workspace: someone who belongs to two
            // companies should not read one's reminders while looking at the
            // other.
            'notifications' => $request->user()
                ->notifications()
                ->where('data->workspace_id', $workspace->id)
                ->latest()
                ->paginate(30),
        ]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('status', 'همه‌ی اعلان‌ها خوانده‌شده علامت خوردند.');
    }
}
