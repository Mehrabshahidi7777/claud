<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Enums\FollowUpStep;
use App\Models\Holiday;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\TaskReminder;
use App\Services\FollowUpRunner;
use App\Services\FollowUpScheduler;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationsAndHolidaysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsDriver::class, new FakeSmsDriver);

        CarbonImmutable::setTestNow('2026-09-22 09:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_free_rungs_actually_notify_somebody(): void
    {
        // They used to advance the task's status and do nothing else, which
        // made the first two days of the ladder invisible.
        Notification::fake();

        [$workspace, $assignee] = $this->workspace();

        $task = Task::factory()->for($workspace)->create([
            'assignee_id' => $assignee->id,
            'creator_id' => $assignee->id,
            'due_at' => now()->addDays(3),
        ]);

        app(FollowUpScheduler::class)->scheduleFor($task);

        // Move to just after the nudge, which falls 24 hours before the deadline.
        CarbonImmutable::setTestNow(now()->addDays(2)->addHour());
        app(FollowUpRunner::class)->sweep();

        Notification::assertSentTo($assignee, TaskReminder::class);
    }

    public function test_the_reminder_is_stored_so_it_can_be_read_later(): void
    {
        [$workspace, $assignee] = $this->workspace();

        $task = Task::factory()->for($workspace)->create([
            'assignee_id' => $assignee->id,
            'creator_id' => $assignee->id,
            'due_at' => now()->addDays(3),
            'title' => 'نصب کولر گازی',
        ]);

        app(FollowUpScheduler::class)->scheduleFor($task);

        CarbonImmutable::setTestNow(now()->addDays(2)->addHour());
        app(FollowUpRunner::class)->sweep();

        $notification = $assignee->fresh()->notifications()->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('نصب کولر گازی', $notification->data['message']);
        $this->assertSame(FollowUpStep::Nudge->value, $notification->data['step']);
    }

    public function test_the_notifications_page_shows_only_this_workspaces_reminders(): void
    {
        [$workspace, $user] = $this->workspace();

        $other = Workspace::factory()->create();
        $otherTask = Task::factory()->for($other)->create([
            'assignee_id' => $user->id,
            'creator_id' => $user->id,
            'title' => 'کار محرمانه شرکت دیگر',
        ]);

        $user->notify(new TaskReminder($otherTask, FollowUpStep::Nudge));

        $ourTask = Task::factory()->for($workspace)->create([
            'assignee_id' => $user->id,
            'creator_id' => $user->id,
            'title' => 'کار خودمان',
        ]);

        $user->notify(new TaskReminder($ourTask, FollowUpStep::Nudge));

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('کار خودمان')
            ->assertDontSee('کار محرمانه شرکت دیگر');
    }

    public function test_marking_everything_read_clears_the_badge(): void
    {
        [$workspace, $user] = $this->workspace();

        $task = Task::factory()->for($workspace)->create([
            'assignee_id' => $user->id,
            'creator_id' => $user->id,
        ]);

        $user->notify(new TaskReminder($task, FollowUpStep::Nudge));

        $this->assertSame(1, $user->fresh()->unreadNotifications()->count());

        $this->actingAs($user)->post(route('notifications.read'))->assertRedirect();

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_seeding_a_year_writes_the_fixed_holidays(): void
    {
        Holiday::query()->delete();
        Holiday::forgetLookup();

        $this->artisan('holidays:seed', ['year' => 1405])->assertSuccessful();

        // Nowruz is 1 Farvardin, which is 21 March 2026 for 1405.
        $this->assertDatabaseHas('holidays', ['date' => '2026-03-21', 'title' => 'نوروز']);
        $this->assertSame(10, Holiday::count());
    }

    public function test_seeding_the_same_year_twice_adds_nothing(): void
    {
        Holiday::query()->delete();

        $this->artisan('holidays:seed', ['year' => 1405]);
        $this->artisan('holidays:seed', ['year' => 1405]);

        $this->assertSame(10, Holiday::count());
    }

    public function test_a_lunar_holiday_can_be_added_by_hand(): void
    {
        // These move about eleven days a year against the solar calendar, so
        // they cannot be derived — and without them the engine texts people
        // on Ashura.
        $this->artisan('holidays:add', ['date' => '1405/04/12', 'title' => 'عاشورا'])
            ->assertSuccessful();

        $this->assertDatabaseHas('holidays', ['title' => 'عاشورا']);
    }

    public function test_an_impossible_date_is_refused(): void
    {
        $this->artisan('holidays:add', ['date' => '1405/13/40', 'title' => 'نامعتبر'])
            ->assertFailed();

        $this->assertDatabaseMissing('holidays', ['title' => 'نامعتبر']);
    }

    /**
     * @return array{0: Workspace, 1: User}
     */
    private function workspace(): array
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $workspace->members()->attach($user, ['role' => 'owner']);

        return [$workspace, $user];
    }
}
