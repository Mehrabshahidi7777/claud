<?php

namespace App\Console\Commands;

use App\Models\Receivable;
use App\Models\Workspace;
use App\Services\ReceivableChaser;
use Illuminate\Console\Command;

class ChaseReceivables extends Command
{
    protected $signature = 'receivables:chase
                            {--workspace= : Only sweep this workspace}
                            {--dry : Report what is overdue without raising anything}';

    protected $description = 'Turn overdue receivables into tasks the follow-up engine chases';

    public function handle(ReceivableChaser $chaser): int
    {
        if ($this->option('dry')) {
            $grace = (int) config('finance.chase_after_days', 3);

            $due = Receivable::query()
                ->outstanding()
                ->whereNull('task_id')
                ->whereDate('due_on', '<', now()->subDays($grace)->toDateString())
                ->count();

            $this->info("$due overdue receivable(s) would be chased. Nothing raised (--dry).");

            return self::SUCCESS;
        }

        $workspaceId = $this->option('workspace');

        $raised = $workspaceId !== null
            ? $chaser->sweepWorkspace(Workspace::findOrFail($workspaceId))
            : $chaser->sweep();

        $this->info("Raised $raised chase task(s).");

        return self::SUCCESS;
    }
}
