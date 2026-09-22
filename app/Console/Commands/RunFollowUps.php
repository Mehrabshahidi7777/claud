<?php

namespace App\Console\Commands;

use App\Models\TaskFollowUp;
use App\Services\FollowUpRunner;
use Illuminate\Console\Command;

class RunFollowUps extends Command
{
    protected $signature = 'followups:run {--dry : Report what is due without sending anything}';

    protected $description = 'Fire every follow-up whose moment has come';

    public function handle(FollowUpRunner $runner): int
    {
        if ($this->option('dry')) {
            $due = TaskFollowUp::query()->due()->count();
            $this->info("$due follow-up(s) due. Nothing sent (--dry).");

            return self::SUCCESS;
        }

        $processed = $runner->sweep();

        $this->info("Processed $processed follow-up(s).");

        return self::SUCCESS;
    }
}
