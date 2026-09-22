<?php

namespace App\Services;

use App\Enums\WorkspaceRole;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resolves which workspace the signed-in user is acting in, and refuses to let
 * anything from another one through.
 *
 * Every tenant-scoped read goes through here rather than trusting an id from
 * the request, which is the one mistake in a multi-tenant application that
 * ends a company.
 */
class CurrentWorkspace
{
    private ?Workspace $resolved = null;

    public function get(): Workspace
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $user = Auth::user();

        if ($user === null) {
            throw new HttpException(403, 'Not signed in.');
        }

        $workspaceId = session('workspace_id');

        $workspace = $workspaceId !== null
            ? $user->workspaces()->where('workspaces.id', $workspaceId)->first()
            : null;

        // Falling back to the first membership keeps a stale or tampered
        // session id from locking someone out of their own account.
        $workspace ??= $user->workspaces()->first();

        if ($workspace === null) {
            throw new HttpException(403, 'No workspace.');
        }

        session(['workspace_id' => $workspace->id]);

        return $this->resolved = $workspace;
    }

    public function role(): WorkspaceRole
    {
        return WorkspaceRole::from($this->get()->members()
            ->where('users.id', Auth::id())
            ->first()
            ?->pivot
            ?->role ?? WorkspaceRole::Guest->value);
    }

    /**
     * A task from another workspace is a 404, not a 403: telling someone that
     * a record exists but is not theirs is itself a leak.
     */
    public function authorize(Task $task): void
    {
        abort_unless($task->workspace_id === $this->get()->id, 404);
    }

    public function switchTo(int $workspaceId): bool
    {
        $workspace = Auth::user()?->workspaces()->where('workspaces.id', $workspaceId)->first();

        if ($workspace === null) {
            return false;
        }

        session(['workspace_id' => $workspace->id]);
        $this->resolved = $workspace;

        return true;
    }
}
