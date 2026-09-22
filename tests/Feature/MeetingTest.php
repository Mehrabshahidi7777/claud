<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Contracts\SmsDriver;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Sms\Drivers\FakeSmsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The risk here is the opposite of the one in TaskParser: meeting notes are
 * full of discussion that nobody committed to, and a model left to itself
 * turns "we should look at the pricing" into a task the engine then chases
 * someone about.
 */
class MeetingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsDriver::class, new FakeSmsDriver);

        CarbonImmutable::setTestNow('2026-09-22 09:00:00');

        $this->workspace = Workspace::factory()->create();
        $this->owner = User::factory()->create(['name' => 'مهراب شهیدی']);
        $this->workspace->members()->attach($this->owner, ['role' => 'owner']);

        foreach (['رضا مرادی', 'حسین نجفی'] as $name) {
            $this->workspace->members()->attach(
                User::factory()->create(['name' => $name]),
                ['role' => 'member'],
            );
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_notes_are_kept_even_when_no_model_is_configured(): void
    {
        // The record is worth having on its own, and it is the only way to
        // re-run the extraction once a model is available.
        $this->fakeModel(null);

        $this->actingAs($this->owner)->post(route('meetings.store'), [
            'title' => 'جلسه هفتگی عملیات',
            'notes' => 'رضا تا پنجشنبه گزارش سرویس‌ها را آماده کند و با کارفرما هماهنگ شود.',
        ])->assertRedirect();

        $meeting = Meeting::first();

        $this->assertNotNull($meeting);
        $this->assertStringContainsString('رضا', $meeting->notes);
        $this->assertFalse($meeting->processed_by_ai);
        $this->assertNull($meeting->summary);
    }

    public function test_it_stores_the_summary_and_decisions_the_model_returns(): void
    {
        $this->fakeModel([
            'summary' => str_repeat('جلسه درباره وضعیت پروژه‌های جاری برگزار شد. ', 2),
            'decisions' => ['قیمت پروژه جردن تغییر نمی‌کند'],
            'actions' => [],
        ]);

        $this->actingAs($this->owner)->post(route('meetings.store'), [
            'title' => 'جلسه هفتگی',
            'notes' => 'متن جلسه که به اندازه کافی طولانی است برای اعتبارسنجی.',
        ]);

        $meeting = Meeting::first();

        $this->assertTrue($meeting->processed_by_ai);
        $this->assertStringContainsString('پروژه‌های جاری', $meeting->summary);
        $this->assertSame(['قیمت پروژه جردن تغییر نمی‌کند'], $meeting->decisions);
    }

    public function test_action_items_come_back_as_drafts_and_create_nothing(): void
    {
        $this->fakeModel([
            'summary' => str_repeat('خلاصه‌ای به اندازه کافی طولانی برای پذیرفته شدن. ', 2),
            'decisions' => [],
            'actions' => [
                ['title' => 'آماده کردن گزارش سرویس‌ها', 'assignee' => 'رضا مرادی', 'due_date' => '1405/07/20', 'priority' => 'normal'],
            ],
        ]);

        $response = $this->actingAs($this->owner)->post(route('meetings.store'), [
            'title' => 'جلسه هفتگی',
            'notes' => 'متن جلسه که به اندازه کافی طولانی است برای اعتبارسنجی.',
        ]);

        $response->assertSessionHas('draftActions');

        // Nothing is written until the manager confirms an item.
        $this->assertSame(0, Task::count());
    }

    public function test_confirming_a_draft_creates_the_task_and_schedules_its_ladder(): void
    {
        $meeting = Meeting::factory()->for($this->workspace)->create(['created_by' => $this->owner->id]);
        $reza = $this->workspace->members()->where('name', 'رضا مرادی')->first();

        $this->actingAs($this->owner)->post(route('meetings.actions.confirm', $meeting), [
            'title' => 'آماده کردن گزارش سرویس‌ها',
            'assignee_id' => $reza->id,
            'due_date' => '1405/07/20',
            'priority' => 'normal',
        ])->assertRedirect();

        $task = Task::first();

        $this->assertNotNull($task);
        $this->assertSame($meeting->id, $task->meeting_id);
        $this->assertSame($reza->id, $task->assignee_id);
        $this->assertGreaterThan(0, $task->followUps()->count());
    }

    public function test_a_confirmed_task_remembers_which_meeting_agreed_it(): void
    {
        // "Why is this on my list" is answered months later by the meeting.
        $meeting = Meeting::factory()->for($this->workspace)->create(['created_by' => $this->owner->id]);

        $this->actingAs($this->owner)->post(route('meetings.actions.confirm', $meeting), [
            'title' => 'تماس با کارفرما',
            'priority' => 'normal',
        ]);

        $this->actingAs($this->owner)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee('تماس با کارفرما');
    }

    public function test_a_name_the_model_invented_is_not_assigned_to_anyone(): void
    {
        $this->fakeModel([
            'summary' => str_repeat('خلاصه‌ای به اندازه کافی طولانی برای پذیرفته شدن. ', 2),
            'decisions' => [],
            'actions' => [
                ['title' => 'تماس با کارفرما', 'assignee' => 'رضا مرادیان', 'due_date' => '', 'priority' => 'normal'],
            ],
        ]);

        $this->actingAs($this->owner)->post(route('meetings.store'), [
            'title' => 'جلسه',
            'notes' => 'متن جلسه که به اندازه کافی طولانی است برای اعتبارسنجی.',
        ]);

        $drafts = session('draftActions');

        $this->assertNull($drafts[0]['assignee_id']);
    }

    public function test_a_one_word_summary_is_rejected(): void
    {
        $this->fakeModel([
            'summary' => 'خوب',
            'decisions' => [],
            'actions' => [],
        ]);

        $this->actingAs($this->owner)->post(route('meetings.store'), [
            'title' => 'جلسه',
            'notes' => 'متن جلسه که به اندازه کافی طولانی است برای اعتبارسنجی.',
        ]);

        $this->assertNull(Meeting::first()->summary);
    }

    public function test_an_implausible_date_is_dropped_from_a_draft(): void
    {
        $this->fakeModel([
            'summary' => str_repeat('خلاصه‌ای به اندازه کافی طولانی برای پذیرفته شدن. ', 2),
            'decisions' => [],
            'actions' => [
                ['title' => 'تماس با کارفرما', 'assignee' => '', 'due_date' => '1399/01/01', 'priority' => 'normal'],
            ],
        ]);

        $this->actingAs($this->owner)->post(route('meetings.store'), [
            'title' => 'جلسه',
            'notes' => 'متن جلسه که به اندازه کافی طولانی است برای اعتبارسنجی.',
        ]);

        $this->assertNull(session('draftActions')[0]['due_date']);
    }

    public function test_a_wildly_overlong_answer_is_capped(): void
    {
        $this->fakeModel([
            'summary' => str_repeat('خلاصه‌ای به اندازه کافی طولانی برای پذیرفته شدن. ', 2),
            'decisions' => [],
            'actions' => array_fill(0, 40, [
                'title' => 'اقدام تکراری', 'assignee' => '', 'due_date' => '', 'priority' => 'normal',
            ]),
        ]);

        $this->actingAs($this->owner)->post(route('meetings.store'), [
            'title' => 'جلسه',
            'notes' => 'متن جلسه که به اندازه کافی طولانی است برای اعتبارسنجی.',
        ]);

        $this->assertCount(15, session('draftActions'));
    }

    public function test_another_workspaces_meeting_is_invisible(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        $stranger = User::factory()->create();
        $otherWorkspace->members()->attach($stranger, ['role' => 'owner']);

        $theirMeeting = Meeting::factory()->for($otherWorkspace)->create(['created_by' => $stranger->id]);

        $this->actingAs($this->owner)
            ->get(route('meetings.show', $theirMeeting))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->post(route('meetings.actions.confirm', $theirMeeting), [
                'title' => 'نفوذ',
                'priority' => 'normal',
            ])
            ->assertNotFound();
    }

    public function test_notes_can_be_run_through_the_model_again(): void
    {
        // The extraction rules will be wrong in the first months; this is why
        // the raw text is kept.
        $meeting = Meeting::factory()->for($this->workspace)->create(['created_by' => $this->owner->id]);

        $this->fakeModel([
            'summary' => str_repeat('خلاصه‌ی تازه که به اندازه کافی طولانی است. ', 2),
            'decisions' => [],
            'actions' => [],
        ]);

        $this->actingAs($this->owner)
            ->post(route('meetings.reparse', $meeting))
            ->assertRedirect();

        $this->assertStringContainsString('خلاصه‌ی تازه', $meeting->fresh()->summary);
    }

    public function test_reparsing_says_plainly_when_the_model_is_unavailable(): void
    {
        $meeting = Meeting::factory()->for($this->workspace)->create(['created_by' => $this->owner->id]);

        $this->fakeModel(null);

        $this->actingAs($this->owner)
            ->post(route('meetings.reparse', $meeting))
            ->assertSessionHasErrors('ai');
    }

    /**
     * @param  array<string, mixed>|null  $answer
     */
    private function fakeModel(?array $answer): void
    {
        $this->app->instance(AiProvider::class, new class($answer) implements AiProvider
        {
            public function __construct(private readonly ?array $answer) {}

            public function structured(
                string $systemPrompt,
                string $userInput,
                array $schema,
                string $purpose = 'extraction',
            ): ?array {
                return $this->answer;
            }

            public function isAvailable(): bool
            {
                return $this->answer !== null;
            }
        });
    }
}
