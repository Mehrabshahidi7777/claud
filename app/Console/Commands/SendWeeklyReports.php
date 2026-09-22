<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\WeeklyReportDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendWeeklyReports extends Command
{
    protected $signature = 'reports:weekly
                            {--workspace= : Send for one workspace, ignoring its schedule}
                            {--force : Send even when the local day and hour do not match}';

    protected $description = 'Send each workspace its weekly report when its own Saturday morning arrives';

    /**
     * Runs hourly rather than once a week, because "Saturday at 08:00" means a
     * different instant for every workspace and a single cron expression can
     * only be right for one timezone. Each workspace is asked whether its own
     * moment has come; the unique index on the report makes a second ask
     * harmless.
     */
    public function handle(WeeklyReportDispatcher $dispatcher): int
    {
        $now = CarbonImmutable::now();

        $workspaces = Workspace::query()
            ->when($this->option('workspace'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        $sent = 0;

        foreach ($workspaces as $workspace) {
            if (! $this->option('force') && ! $this->option('workspace') && ! $this->isDue($workspace, $now)) {
                continue;
            }

            $report = $dispatcher->dispatchFor($workspace, $now);

            if ($report === null) {
                $this->line("{$workspace->name}: گزارش این دوره قبلاً فرستاده شده.");

                continue;
            }

            $sent++;
            $this->info("{$workspace->name}: گزارش فرستاده شد — {$report->url()}");
        }

        $this->info("$sent report(s) sent.");

        return self::SUCCESS;
    }

    /**
     * The workspace's own configured morning, read in its own timezone.
     * Defaults to Saturday at 08:00, the first working hour of the Iranian
     * week — a report that lands on Friday is read on Sunday, by which point
     * two more days have gone wrong.
     */
    private function isDue(Workspace $workspace, CarbonImmutable $now): bool
    {
        $local = $now->setTimezone($workspace->timezone());

        $day = (int) $workspace->setting('report.day_of_week', 6);
        $hour = (int) $workspace->setting('report.hour', 8);

        return $local->dayOfWeek === $day && $local->hour === $hour;
    }
}
