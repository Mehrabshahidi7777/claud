<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The third and last field of sign-up: who you are and what your company is
 * called. Reached only when a signed-in user has neither.
 */
class OnboardingController extends Controller
{
    public function show(Request $request)
    {
        if ($request->user()->name !== '' && $request->user()->workspaces()->exists()) {
            return redirect()->route('tasks.index');
        }

        return view('auth.onboarding');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'workspace' => ['required', 'string', 'max:100'],
        ]);

        $user = $request->user();

        DB::transaction(function () use ($user, $validated) {
            $user->update(['name' => $validated['name']]);

            $workspace = Workspace::create(['name' => $validated['workspace']]);

            // Whoever creates the workspace owns it, which also makes them the
            // default destination for an escalation that has nowhere else to go.
            $workspace->members()->attach($user, ['role' => WorkspaceRole::Owner->value]);
        });

        return redirect()->route('tasks.index');
    }
}
