<?php

namespace App\Console\Commands;

use App\Models\RecurringTask;
use App\Models\Workspace;
use App\Services\RecurrenceSweeper;
use Illuminate\Console\Command;

class RunRecurrences extends Command
{
    protected $signature = 'recurrences:run
                            {--workspace= : Only sweep this workspace}
                            {--dry : Report what is due without raising anything}';

    protected $description = 'Raise the next occurrence of every recurring task that has come round';

    public function handle(RecurrenceSweeper $sweeper): int
    {
        if ($this->option('dry')) {
            $due = RecurringTask::query()->active()->get()->filter->isDueToRaise()->count();

            $this->info("$due recurring task(s) due to raise. Nothing created (--dry).");

            return self::SUCCESS;
        }

        $workspaceId = $this->option('workspace');

        $raised = $workspaceId !== null
            ? $sweeper->sweepWorkspace(Workspace::findOrFail($workspaceId))
            : $sweeper->sweep();

        $this->info("Raised $raised occurrence(s).");

        return self::SUCCESS;
    }
}
