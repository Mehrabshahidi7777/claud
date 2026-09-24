<?php

namespace Database\Seeders;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\ContractKind;
use App\Enums\ExpenseCategory;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpStep;
use App\Enums\PartyType;
use App\Enums\ReceivableStatus;
use App\Enums\RecurrenceAnchor;
use App\Enums\RecurrenceUnit;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceType;
use App\Models\ApprovalRequest;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Holiday;
use App\Models\Meeting;
use App\Models\Receivable;
use App\Models\RecurringTask;
use App\Models\SmsOutbound;
use App\Models\Task;
use App\Models\TaskFollowUp;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\Workspace;
use App\Notifications\TaskReminder;
use App\Services\BillingService;
use App\Services\ContractWatcher;
use App\Services\FollowUpScheduler;
use App\Services\ReceivableChaser;
use App\Services\RecurrenceSweeper;
use App\Services\WeeklyReportDispatcher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

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

        // A real workspace gets its trial at onboarding; the demo needs one
        // too, or the paywall blocks the demo itself.
        app(BillingService::class)->startTrial($workspace);

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

        // A task whose gentle reminder has already fired, so the notification
        // page is alive in the demo. An empty page there reads as a feature
        // that does not work.
        // Assigned to the owner, who is who the demo signs in as. A manager
        // with no work of their own sees an empty notification page and reads
        // the feature as broken.
        $this->seedNudgedTask($workspace, $owner, $opsManager);

        $this->seedChasedTask($workspace, $technicians->first(), $opsManager);
        $this->seedEscalatedTask($workspace, $technicians->get(1), $opsManager);
        $this->seedDeferredTask($workspace, $technicians->get(2), $opsManager);

        $this->seedMeeting($workspace, $owner, $opsManager, $technicians);
        $this->seedApprovals($workspace, $owner, $opsManager, $technicians->first());

        $this->seedFinance($workspace, $owner, $opsManager);
        $this->seedRecurring($workspace, $owner, $opsManager, $technicians);
        $this->seedContracts($workspace, $owner, $opsManager, $technicians);

        $this->seedPastWeeklyReports($workspace);

        // Built last, so it snapshots the money, contracts and services the
        // seeder has just created. The demo then opens on a report that
        // actually has something in every section.
        app(WeeklyReportDispatcher::class)->dispatchFor($workspace);

        $this->seedHouseholdWorkspace($owner);

        $this->command->info('Demo workspace ready.');
        $this->command->info('Sign in with 09121110001 — the code is in storage/logs/laravel.log on the log driver.');
    }

    /**
     * The first rung, already fired. It costs nothing and arrives before
     * anything has gone wrong — which is exactly why the SMS rungs stay rare
     * enough to still be taken seriously.
     */
    private function seedNudgedTask(Workspace $workspace, User $assignee, User $manager): void
    {
        $task = Task::create([
            'workspace_id' => $workspace->id,
            'title' => 'بررسی و تأیید پیش‌فاکتور پروژه جردن',
            'assignee_id' => $assignee->id,
            'creator_id' => $manager->id,
            'due_at' => now()->addHours(20)->setTime(17, 0),
            'priority' => TaskPriority::Normal,
            'status' => TaskStatus::Nudged,
        ]);

        TaskFollowUp::create([
            'task_id' => $task->id,
            'step' => FollowUpStep::Nudge->value,
            'channel' => 'notification',
            'recipient_id' => $assignee->id,
            'scheduled_at' => now()->subHours(4),
            'sent_at' => now()->subHours(4),
            'status' => FollowUpStatus::Sent,
        ]);

        $assignee->notify(new TaskReminder($task, FollowUpStep::Nudge));
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
     * A meeting whose notes have already been read, with two of its action
     * items confirmed into tasks.
     *
     * The line that sells this page is the one in the notes that did *not*
     * become a task: "باید یک فکری برای قیمت‌گذاری بکنیم" is a discussion, not
     * a commitment, and a manager who sees it left alone believes the rest.
     *
     * @param  Collection<int, User>  $technicians
     */
    private function seedMeeting(Workspace $workspace, User $owner, User $manager, $technicians): void
    {
        $meeting = Meeting::create([
            'workspace_id' => $workspace->id,
            'created_by' => $manager->id,
            'title' => 'جلسه هفتگی عملیات',
            'held_at' => now()->subDays(2)->setTime(10, 0),
            'notes' => <<<'NOTES'
            درباره پروژه جردن صحبت شد. کارفرما نقشه‌های اصلاحی را فرستاده و باید تا
            هفته آینده بررسی شود. رضا قبول کرد تا پنجشنبه گزارش سرویس‌های شهریور را
            آماده کند. حسین گفت فردا با کارفرمای میرداماد تماس می‌گیرد و نتیجه را
            اعلام می‌کند. درباره قیمت‌گذاری پروژه‌های کوچک بحث شد، باید یک فکری
            برایش بکنیم ولی فعلاً به نتیجه نرسیدیم. قرار شد خرید دستگاه جوش جدید
            تا پایان ماه بررسی شود.
            NOTES,
            'summary' => 'نقشه‌های اصلاحی پروژه جردن رسیده و باید تا هفته آینده بررسی شود. '
                .'گزارش سرویس‌های شهریور تا پنجشنبه آماده می‌شود و تماس با کارفرمای میرداماد فردا انجام می‌گیرد. '
                .'قیمت‌گذاری پروژه‌های کوچک بدون نتیجه ماند.',
            'decisions' => [
                'بررسی نقشه‌های اصلاحی جردن تا هفته آینده',
                'خرید دستگاه جوش جدید تا پایان ماه بررسی شود',
            ],
            'processed_by_ai' => true,
        ]);

        foreach ([
            ['تهیه گزارش سرویس‌های شهریور', $technicians->first(), 3],
            ['تماس با کارفرمای پروژه میرداماد', $technicians->get(1), 1],
        ] as [$title, $assignee, $inDays]) {
            $task = Task::create([
                'workspace_id' => $workspace->id,
                'meeting_id' => $meeting->id,
                'title' => $title,
                'assignee_id' => $assignee->id,
                'creator_id' => $manager->id,
                'due_at' => now()->addDays($inDays)->setTime(17, 0),
                'priority' => TaskPriority::Normal,
                'status' => TaskStatus::Open,
            ]);

            app(FollowUpScheduler::class)->scheduleFor($task);
        }
    }

    /**
     * One request waiting on the person the demo signs in as, and one already
     * answered.
     *
     * The pending one is leave that overlaps an open task on purpose: the
     * approval screen shows the clash before the decision, which is the whole
     * argument for keeping leave in the same system as the work.
     */
    private function seedApprovals(Workspace $workspace, User $owner, User $manager, User $technician): void
    {
        ApprovalRequest::create([
            'workspace_id' => $workspace->id,
            'requester_id' => $manager->id,
            'approver_id' => $owner->id,
            'type' => ApprovalType::Leave,
            'status' => ApprovalStatus::Pending,
            'title' => 'مرخصی استحقاقی',
            'reason' => 'سفر خانوادگی، از قبل هماهنگ شده بود.',
            'starts_on' => now()->addDays(6)->toDateString(),
            'ends_on' => now()->addDays(9)->toDateString(),
        ]);

        // The task that falls inside that window, so the clash box has
        // something to show.
        Task::create([
            'workspace_id' => $workspace->id,
            'title' => 'تحویل صورت‌وضعیت ماهانه به کارفرما',
            'assignee_id' => $manager->id,
            'creator_id' => $owner->id,
            'due_at' => now()->addDays(7)->setTime(17, 0),
            'priority' => TaskPriority::High,
            'status' => TaskStatus::Open,
        ]);

        ApprovalRequest::create([
            'workspace_id' => $workspace->id,
            'requester_id' => $owner->id,
            'approver_id' => null,
            'type' => ApprovalType::Purchase,
            'status' => ApprovalStatus::Approved,
            'title' => 'خرید دستگاه جوش',
            'amount' => 180_000_000,
            'decided_by' => $manager->id,
            'decided_at' => now()->subDay(),
            'decision_note' => 'از بودجه تجهیزات سه‌ماهه.',
            'created_at' => now()->subDays(3),
        ]);

        ApprovalRequest::create([
            'workspace_id' => $workspace->id,
            'requester_id' => $technician->id,
            'approver_id' => $manager->id,
            'type' => ApprovalType::Expense,
            'status' => ApprovalStatus::Rejected,
            'title' => 'هزینه ایاب و ذهاب پروژه کرج',
            'amount' => 4_500_000,
            'decided_by' => $manager->id,
            'decided_at' => now()->subDays(4),
            'decision_note' => 'با فاکتور رسمی دوباره ثبت شود.',
            'created_at' => now()->subDays(5),
        ]);
    }

    /**
     * Service contracts, the module that makes money rather than reporting it.
     *
     * Two of them are deliberately past their date and priced, so the demo
     * opens on a real figure: this much revenue is sitting there because
     * nobody rang the customer. That number is the argument for the
     * subscription, made in the customer's own currency.
     *
     * @param  Collection<int, User>  $technicians
     */
    private function seedRecurring(Workspace $workspace, User $owner, User $manager, $technicians): void
    {
        foreach ([
            ['سرویس شش‌ماهه چیلرها', 'کارخانه شیمیایی پارس', 6, RecurrenceUnit::Month, 240_000_000, -34, 3],
            ['سرویس دوره‌ای هواسازها', 'مجتمع تجاری الهیه', 3, RecurrenceUnit::Month, 85_000_000, -9, 7],
            ['بازدید فنی موتورخانه', 'برج نگین', 6, RecurrenceUnit::Month, 120_000_000, 12, 2],
            ['سرویس سالانه پکیج‌ها', 'شرکت ساختمانی نگین', 1, RecurrenceUnit::Year, 310_000_000, 74, 4],
        ] as [$title, $customer, $count, $unit, $value, $dueInDays, $done]) {
            RecurringTask::create([
                'workspace_id' => $workspace->id,
                'created_by' => $owner->id,
                'assignee_id' => $technicians->random()->id,
                'title' => $title,
                'customer_name' => $customer,
                'customer_phone' => '021887766'.random_int(10, 99),
                'interval_unit' => $unit,
                'interval_count' => $count,
                'anchor' => RecurrenceAnchor::Completion,
                'lead_days' => 14,
                'next_due_on' => now()->addDays($dueInDays)->toDateString(),
                'last_done_on' => now()->subDays(abs($dueInDays) + 30)->toDateString(),
                'occurrences' => $done,
                'estimated_value' => $value,
                'priority' => TaskPriority::Normal,
            ]);
        }

        // The household kind, on the same engine: no customer, no price.
        RecurringTask::create([
            'workspace_id' => $workspace->id,
            'created_by' => $manager->id,
            'assignee_id' => $manager->id,
            'title' => 'تمدید بیمه شخص ثالث خودروهای شرکت',
            'interval_unit' => RecurrenceUnit::Year,
            'interval_count' => 1,
            'anchor' => RecurrenceAnchor::Scheduled,
            'lead_days' => 30,
            'next_due_on' => now()->addDays(21)->toDateString(),
            'priority' => TaskPriority::High,
        ]);

        app(RecurrenceSweeper::class)->sweepWorkspace($workspace);
    }

    /**
     * A second workspace, on the family plan, owned by the same person.
     *
     * The switcher in the header then demonstrates the thing that is hardest
     * to explain in words: the same account, and a visibly different product.
     * No finance, no contracts, no meetings, and no escalation to anybody's
     * manager — because there isn't one.
     */
    private function seedHouseholdWorkspace(User $owner): void
    {
        $household = Workspace::create([
            'name' => 'خانه',
            'type' => WorkspaceType::Family,
            'timezone' => 'Asia/Tehran',
            'sms_quota' => 100,
            'sms_used' => 4,
            'sms_period_started_at' => now()->startOfMonth(),
        ]);

        $household->members()->attach($owner, ['role' => WorkspaceRole::Owner->value]);

        $partner = User::firstOrCreate(
            ['phone' => '989121110006'],
            ['name' => 'مریم شهیدی', 'phone_verified_at' => now()],
        );

        $household->members()->attach($partner, ['role' => WorkspaceRole::Admin->value]);

        app(BillingService::class)->startTrial($household);

        foreach ([
            ['تمدید بیمه شخص ثالث', 1, RecurrenceUnit::Year, 34, RecurrenceAnchor::Scheduled],
            ['تعویض روغن ماشین', 4, RecurrenceUnit::Month, -6, RecurrenceAnchor::Completion],
            ['پرداخت قبض برق', 2, RecurrenceUnit::Month, 9, RecurrenceAnchor::Scheduled],
            ['سرویس پکیج خانه', 1, RecurrenceUnit::Year, 120, RecurrenceAnchor::Completion],
        ] as [$title, $count, $unit, $dueInDays, $anchor]) {
            RecurringTask::create([
                'workspace_id' => $household->id,
                'created_by' => $owner->id,
                'assignee_id' => $dueInDays < 30 ? $owner->id : $partner->id,
                'title' => $title,
                'interval_unit' => $unit,
                'interval_count' => $count,
                'anchor' => $anchor,
                'lead_days' => 14,
                'next_due_on' => now()->addDays($dueInDays)->toDateString(),
                'priority' => TaskPriority::Normal,
            ]);
        }

        $task = Task::create([
            'workspace_id' => $household->id,
            'title' => 'گرفتن نوبت دندانپزشکی برای آیدا',
            'assignee_id' => $partner->id,
            'creator_id' => $owner->id,
            'due_at' => now()->addDays(2)->setTime(17, 0),
            'priority' => TaskPriority::Normal,
            'status' => TaskStatus::Open,
        ]);

        app(FollowUpScheduler::class)->scheduleFor($task);
        app(RecurrenceSweeper::class)->sweepWorkspace($household);
    }

    /**
     * Contracts and licences, two of them already lapsed.
     *
     * The expired contractor qualification is the row that lands in a demo:
     * it is not untidy, it disqualifies the company from tenders it has
     * already paid to bid for — and nobody finds out until the bid comes
     * back rejected.
     *
     * @param  Collection<int, User>  $technicians
     */
    private function seedContracts(Workspace $workspace, User $owner, User $manager, $technicians): void
    {
        foreach ($technicians as $index => $technician) {
            Contract::create([
                'workspace_id' => $workspace->id,
                'created_by' => $owner->id,
                'owner_id' => $manager->id,
                'kind' => ContractKind::Employment,
                'party_type' => PartyType::Employee,
                'party_name' => $technician->name,
                'party_user_id' => $technician->id,
                'title' => 'قرارداد یک‌ساله',
                'reference' => 'HR-14'.(4 + $index).'-0'.($index + 3),
                'starts_on' => now()->subMonths(11 - $index)->toDateString(),
                'expires_on' => now()->addDays([18, 52, -26][$index])->toDateString(),
                'value' => [1_450_000_000, 1_280_000_000, 1_180_000_000][$index],
                'notice_days' => 60,
                'renewals' => $index,
            ]);
        }

        Contract::create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'owner_id' => $owner->id,
            'kind' => ContractKind::Licence,
            'party_type' => PartyType::Authority,
            'party_name' => 'سازمان برنامه و بودجه',
            'title' => 'گواهینامه صلاحیت پیمانکاری',
            'reference' => 'GS-1404-7781',
            'note' => 'بدون این گواهی، شرکت در مناقصات دولتی رد صلاحیت می‌شود.',
            'starts_on' => now()->subYears(3)->toDateString(),
            'expires_on' => now()->subDays(12)->toDateString(),
            'notice_days' => 45,
            'renewals' => 2,
        ]);

        Contract::create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'owner_id' => $owner->id,
            'kind' => ContractKind::Lease,
            'party_type' => PartyType::Supplier,
            'party_name' => 'آقای موسوی',
            'title' => 'اجاره‌نامه دفتر مرکزی',
            'starts_on' => now()->subMonths(10)->toDateString(),
            'expires_on' => now()->addMonths(2)->toDateString(),
            'value' => 3_120_000_000,
            'notice_days' => 30,
            'auto_renews' => true,
        ]);

        app(ContractWatcher::class)->sweepWorkspace($workspace);
    }

    /**
     * Money going out and money not coming in.
     *
     * The receivables are seeded across the ageing buckets on purpose, with
     * one of them badly overdue and already chased — that row, with a live
     * task behind it, is the single most convincing thing on the screen:
     * every accounting package in the country can print an overdue list, and
     * only this one has put somebody's name on it.
     */
    private function seedFinance(Workspace $workspace, User $owner, User $manager): void
    {
        foreach ([
            [ExpenseCategory::Payroll, 'حقوق مهر ماه', null, 1_850_000_000, 6],
            [ExpenseCategory::Purchase, 'خرید کمپرسور و لوازم یدکی', 'بازرگانی آریا', 420_000_000, 11],
            [ExpenseCategory::Contractor, 'دستمزد اکیپ نصب پروژه الهیه', null, 310_000_000, 14],
            [ExpenseCategory::Transport, 'کرایه حمل تجهیزات به کرج', 'باربری سپهر', 38_000_000, 9],
            [ExpenseCategory::Rent, 'اجاره دفتر مهر', 'آقای موسوی', 260_000_000, 20],
            [ExpenseCategory::Utilities, 'قبض برق و گاز کارگاه', null, 47_000_000, 17],
            [ExpenseCategory::Purchase, 'خرید ابزار دستی', 'فروشگاه سام', 62_000_000, 3],
            [ExpenseCategory::TaxInsurance, 'بیمه تأمین اجتماعی شهریور', null, 540_000_000, 25],
        ] as [$category, $title, $vendor, $amount, $daysAgo]) {
            Expense::create([
                'workspace_id' => $workspace->id,
                'created_by' => $owner->id,
                'category' => $category,
                'title' => $title,
                'vendor' => $vendor,
                'amount' => $amount,
                'spent_on' => now()->subDays($daysAgo)->toDateString(),
            ]);
        }

        foreach ([
            ['شرکت ساختمانی نگین', 'صورت‌وضعیت شماره ۳ برج نگین', 1_240_000_000, -18, 0],
            ['مجتمع تجاری الهیه', 'فاز اول نصب سیستم تهویه', 860_000_000, 12, 0],
            ['کارخانه شیمیایی پارس', 'سرویس سالانه چیلرها', 390_000_000, 47, 0],
            ['دفتر فنی میرداماد', 'تعمیر اضطراری موتورخانه', 145_000_000, 96, 45_000_000],
        ] as [$customer, $title, $amount, $overdueDays, $settled]) {
            Receivable::create([
                'workspace_id' => $workspace->id,
                'created_by' => $owner->id,
                'owner_id' => $manager->id,
                'customer_name' => $customer,
                'title' => $title,
                'amount' => $amount,
                'settled_amount' => $settled,
                'status' => $settled > 0 ? ReceivableStatus::Partial : ReceivableStatus::Open,
                'issued_on' => now()->subDays($overdueDays + 30)->toDateString(),
                'due_on' => now()->subDays($overdueDays)->toDateString(),
            ]);
        }

        // Run the sweep so the demo opens with chase tasks already live rather
        // than with a feature that has to be described instead of shown.
        app(ReceivableChaser::class)->sweepWorkspace($workspace);
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
