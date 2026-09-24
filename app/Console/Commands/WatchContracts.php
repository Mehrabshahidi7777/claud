<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Workspace;
use App\Services\ContractWatcher;
use Illuminate\Console\Command;

class WatchContracts extends Command
{
    protected $signature = 'contracts:watch
                            {--workspace= : Only sweep this workspace}
                            {--dry : Report what is inside its notice window without raising anything}';

    protected $description = 'Raise a renewal task for every contract or licence nearing expiry';

    public function handle(ContractWatcher $watcher): int
    {
        if ($this->option('dry')) {
            $due = Contract::query()
                ->active()
                ->get()
                ->filter(fn (Contract $c) => $c->hasExpired() || $c->isExpiringSoon())
                ->count();

            $this->info("$due contract(s) need a renewal raised. Nothing created (--dry).");

            return self::SUCCESS;
        }

        $workspaceId = $this->option('workspace');

        $raised = $workspaceId !== null
            ? $watcher->sweepWorkspace(Workspace::findOrFail($workspaceId))
            : $watcher->sweep();

        $this->info("Raised $raised renewal task(s).");

        return self::SUCCESS;
    }
}
