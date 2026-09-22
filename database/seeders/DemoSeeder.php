<?php

namespace Database\Seeders;

use App\Enums\FollowUpStatus;
use App\Enums\FollowUpStep;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\Holiday;
use App\Models\SmsOutbound;
use App\Models\Task;
use App\Models\TaskFollowUp;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\Workspace;
use App\Services\FollowUpScheduler;
use Illuminate\Database\Seeder;

/**
 * A demo workspace with the shape of a real customer: a service company with
 * field staff, overdue work, a chase already answered, and one task sitting at
 * an escalation. Without history the report page is empty, and an empty report
 * page sells nothing.
 *
 *     php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::create([
            'name' => 'تأسیسات پارس',
            'timezone' => 'Asia/Tehran',
            'sms_quota' => 500,
            'sms_used' => 46,
            'sms_period_started_at' => now()->startOfMonth(),
        ]);

        $owner = User::create([
            'phone' => '989121110001',
            'name' => 'مهراب شهیدی',
            'phone_verified_at' => now(),
        ]);

        $opsManager = User::create([
            'phone' => '989121110002',
            'name' => 'سعید کریمی',
            'phone_verified_at' => now(),
        ]);

        // The field technicians: created by their manager, never signed in.
        // This is the state the product is built around.
        $technicians = collect([
            ['phone' => '989121110003', 'name' => 'رضا مرادی'],
            ['phone' => '989121110004', 'name' => 'حسین نجفی'],
            ['phone' => '989121110005', 'name' => 'امید صادقی'],
        ])->map(fn ($data) => User::create($data));

        $workspace->members()->attach($owner, ['role' => WorkspaceRole::Owner->value]);
        $workspace->members()->attach($opsManager, [
            'role' => WorkspaceRole::Admin->value,
            'manager_id' => $owner->id,
        ]);

        foreach ($technicians as $technician) {
            $workspace->members()->attach($technician, [
                'role' => WorkspaceRole::Member->value,
                'manager_id' => $opsManager->id,
            ]);
        }

        $this->seedHolidays();

        $scheduler = app(FollowUpScheduler::class);

        // Work closed on time, which is what gives the report a baseline to
        // improve on.
        foreach ([9, 7, 6, 4, 3, 2] as $daysAgo) {
            Task::create([
                'workspace_id' => $workspace->id,
                'title' => fake()->randomElement([
                    'سرویس دوره‌ای چیلر ساختمان مرکزی',
                    'نصب پکیج واحد ۴ برج نگین',
                    'تعویض فیلتر هواساز طبقه دوم',
                    'بازدید فنی پروژه سعادت‌آباد',
                    'تحویل فاکتور پروژه ونک',
                ]),
                'assignee_id' => $technicians->random()->id,
                'creator_id' => $opsManager->id,
                'due_at' => now()->subDays($daysAgo)->setTime(17, 0),
                'priority' => TaskPriority::Normal,
                'status' => TaskStatus::Done,
                'completed_at' => now()->subDays($daysAgo)->setTime(14, 30),
            ]);
        }

        // Two closed late, so the on-time rate is not an implausible 100%.
        foreach ([5, 8] as $daysAgo) {
            Task::create([
                'workspace_id' => $workspace->id,
                'title' => 'پیگیری گارانتی دستگاه مشتری قدیمی',
                'assignee_id' => $technicians->random()->id,
                'creator_id' => $opsManager->id,
                'due_at' => now()->subDays($daysAgo)->setTime(17, 0),
                'priority' => TaskPriority::Normal,
                'status' => TaskStatus::Done,
                'completed_at' => now()->subDays($daysAgo - 2)->setTime(11, 0),
            ]);
        }

        // Open work with its ladder built, which is what the manager sees on
        // the tasks page.
        foreach ([
            ['نصب کولر گازی واحد ۱۲ پروژه الهیه', 1, TaskPriority::Normal],
            ['بازدید فنی و برآورد پروژه جردن', 2, TaskPriority::High],
            ['تهیه گزارش ماهانه سرویس‌ها', 4, TaskPriority::Low],
        ] as [$title, $inDays, $priority]) {
            $task = Task::create([
                'workspace_id' => $workspace->id,
                'title' => $title,
                'assignee_id' => $technicians->random()->id,
                'creator_id' => $opsManager->id,
                'due_at' => now()->addDays($inDays)->setTime(17, 0),
                'priority' => $priority,
                'status' => TaskStatus::Open,
            ]);

            $scheduler->scheduleFor($task);
        }

        $this->seedChasedTask($workspace, $technicians->first(), $opsManager);
        $this->seedEscalatedTask($workspace, $technicians->get(1), $opsManager);
        $this->seedDeferredTask($workspace, $technicians->get(2), $opsManager);

        $this->seedPastWeeklyReports($workspace);

        $this->command->info('Demo workspace ready.');
        $this->command->info('Sign in with 09121110001 — the code is in storage/logs/laravel.log on the log driver.');
    }

    /**
     * A chase sent two hours ago and still unanswered: the escalation is
     * pending, which is what makes the demo's next step visible on screen.
     */
    private function seedChasedTask(Workspace $workspace, User $assignee, User $manager): void
    {
        $task = Task::create([
            'workspace_id' => $workspace->id,
            'title' => 'تعمیر اضطراری موتورخانه پروژه میرداماد',
            'assignee_id' => $assignee->id,
            'creator_id' => $manager->id,
            'due_at' => now()->subHours(4),
            'priority' => TaskPriority::High,
            'status' => TaskStatus::Chased,
        ]);

        $chase = TaskFollowUp::create([
            'task_id' => $task->id,
            'step' => FollowUpStep::Chase->value,
            'channel' => 'sms',
            'recipient_id' => $assignee->id,
            'scheduled_at' => now()->subHours(2),
            'sent_at' => now()->subHours(2),
            'status' => FollowUpStatus::Sent,
        ]);

        SmsOutbound::create([
            'workspace_id' => $workspace->id,
            'user_id' => $assignee->id,
            'task_follow_up_id' => $chase->id,
            'phone' => $assignee->phone,
            'pattern_key' => 'chase',
            'rendered_preview' => $assignee->firstName().'، سررسید: تعمیر اضطراری موتورخانه…',
            'segments' => 1,
            'status' => 'sent',
            'sent_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
        ]);

        TaskFollowUp::create([
            'task_id' => $task->id,
            'step' => FollowUpStep::Escalate->value,
            'channel' => 'sms',
            'recipient_id' => $manager->id,
            'scheduled_at' => now()->addHours(2),
            'status' => FollowUpStatus::Pending,
        ]);
    }

    /**
     * Already escalated, so the report has something in its escalation column
     * and the ratio tile is not null.
     */
    private function seedEscalatedTask(Workspace $workspace, User $assignee, User $manager): void
    {
        $task = Task::create([
            'workspace_id' => $workspace->id,
            'title' => 'تحویل نقشه‌های اجرایی به کارفرما',
            'assignee_id' => $assignee->id,
            'creator_id' => $manager->id,
            'due_at' => now()->subDays(2)->setTime(17, 0),
            'priority' => TaskPriority::High,
            'status' => TaskStatus::Escalated,
        ]);

        foreach ([
            [FollowUpStep::Chase, $assignee->id, now()->subDays(2)->setTime(19, 0)],
            [FollowUpStep::Escalate, $manager->id, now()->subDays(1)->setTime(9, 0)],
        ] as [$step, $recipientId, $moment]) {
            $followUp = TaskFollowUp::create([
                'task_id' => $task->id,
                'step' => $step->value,
                'channel' => 'sms',
                'recipient_id' => $recipientId,
                'scheduled_at' => $moment,
                'sent_at' => $moment,
                'status' => FollowUpStatus::Sent,
            ]);

            SmsOutbound::create([
                'workspace_id' => $workspace->id,
                'user_id' => $recipientId,
                'task_follow_up_id' => $followUp->id,
                'phone' => User::find($recipientId)->phone,
                'pattern_key' => $step->patternKey(),
                'rendered_preview' => 'تحویل نقشه‌های اجرایی…',
                'segments' => 1,
                'status' => 'sent',
                'sent_at' => $moment,
                'created_at' => $moment,
            ]);
        }
    }

    /**
     * Deferred twice, which is the state the design document calls a signal
     * rather than an excuse.
     */
    private function seedDeferredTask(Workspace $workspace, User $assignee, User $manager): void
    {
        Task::create([
            'workspace_id' => $workspace->id,
            'title' => 'جمع‌آوری مدارک بیمه پرسنل',
            'assignee_id' => $assignee->id,
            'creator_id' => $manager->id,
            'due_at' => now()->addDays(3)->setTime(17, 0),
            'priority' => TaskPriority::Normal,
            'status' => TaskStatus::Deferred,
            'defer_count' => 2,
            'defer_reason' => 'منتظر مدارک از حسابداری',
        ]);
    }

    /**
     * Three earlier weeks, with the on-time rate climbing.
     *
     * One week's number means little. A manager deciding whether to renew is
     * looking at the trend, and an archive with a single entry cannot show
     * one — so the demo arrives with a story already in it.
     */
    private function seedPastWeeklyReports(Workspace $workspace): void
    {
        $weeks = [
            ['weeks_ago' => 4, 'rate' => 52.0, 'change' => null, 'overdue' => 6],
            ['weeks_ago' => 3, 'rate' => 58.3, 'change' => 6.3, 'overdue' => 5],
            ['weeks_ago' => 2, 'rate' => 66.7, 'change' => 8.4, 'overdue' => 3],
        ];

        foreach ($weeks as $week) {
            $start = now()->subWeeks($week['weeks_ago'])->startOfWeek();

            WeeklyReport::create([
                'workspace_id' => $workspace->id,
                'period_start' => $start->toDateString(),
                'period_end' => $start->copy()->addDays(7)->toDateString(),
                'metrics' => [
                    'headline' => [
                        'on_time_rate' => $week['rate'],
                        'chase_response_rate' => 62.0,
                        'escalation_ratio' => 22.0,
                    ],
                    'change' => [
                        'on_time_rate' => $week['change'],
                        'chase_response_rate' => null,
                        'escalation_ratio' => null,
                    ],
                    'counts' => [
                        'created' => 11,
                        'closed' => 8,
                        'overdue_now' => $week['overdue'],
                        'escalated' => 1,
                    ],
                    'overdue' => [],
                    'at_risk' => [],
                    'by_member' => [],
                    'sms' => ['sent' => 14, 'used' => 46, 'remaining' => 454],
                ],
                'narrative' => sprintf(
                    'نرخ تکمیل به‌موقع این هفته %s درصد بود. %s کار عقب‌افتاده باقی مانده است.',
                    $week['rate'],
                    $week['overdue'],
                ),
                'narrative_from_ai' => false,
                'created_at' => $start->copy()->addDays(7),
            ]);
        }
    }

    /**
     * Fridays come from the working-week configuration; these are the fixed
     * solar-calendar holidays. Religious dates move each year and are seeded
     * separately by whoever maintains the calendar.
     */
    private function seedHolidays(): void
    {
        foreach ([
            ['2027-03-21', 'نوروز'],
            ['2027-03-22', 'نوروز'],
            ['2027-03-23', 'نوروز'],
            ['2027-03-24', 'نوروز'],
            ['2027-04-01', 'روز طبیعت'],
            ['2026-09-22', 'نمونه تعطیلی'],
        ] as [$date, $title]) {
            Holiday::firstOrCreate(['date' => $date], ['title' => $title]);
        }

        Holiday::forgetLookup();
    }
}
