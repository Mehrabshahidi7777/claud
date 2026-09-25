<?php

namespace Tests\Feature;

use App\Enums\FollowUpStatus;
use App\Enums\FollowUpStep;
use App\Models\Task;
use App\Models\TaskFollowUp;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A screen headed "قدم بعدی سامانه" describing a future action with a past
 * time reads as a bug, not as a queue. The sweep runs every five minutes, so
 * a rung whose moment has passed is about to go out — not something that
 * already happened.
 */
class FollowUpWordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-25 09:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function followUp(array $attributes): TaskFollowUp
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $workspace->members()->attach($user, ['role' => 'owner']);

        $task = Task::factory()->for($workspace)->create([
            'assignee_id' => $user->id,
            'creator_id' => $user->id,
        ]);

        return TaskFollowUp::create(array_merge([
            'task_id' => $task->id,
            'step' => FollowUpStep::Chase->value,
            'channel' => 'sms',
            'recipient_id' => $user->id,
            'status' => FollowUpStatus::Pending,
        ], $attributes));
    }

    public function test_a_rung_still_ahead_says_when(): void
    {
        $followUp = $this->followUp(['scheduled_at' => now()->addHours(3)]);

        $this->assertStringNotContainsString('صف', $followUp->whenDue());
        $this->assertNotSame('', $followUp->whenDue());
    }

    public function test_a_rung_whose_moment_has_passed_is_queued_not_historic(): void
    {
        $followUp = $this->followUp(['scheduled_at' => now()->subMonth()]);

        $this->assertSame('در صف اجرا', $followUp->whenDue());
    }

    public function test_a_sent_rung_reports_when_it_actually_went(): void
    {
        $followUp = $this->followUp([
            'scheduled_at' => now()->subDays(2),
            'sent_at' => now()->subDay(),
            'status' => FollowUpStatus::Sent,
        ]);

        $this->assertStringNotContainsString('صف', $followUp->whenDue());
    }

    public function test_the_dashboard_never_describes_a_pending_rung_in_the_past(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->create();
        $workspace->members()->attach($owner, ['role' => 'owner']);

        $task = Task::factory()->for($workspace)->create([
            'assignee_id' => $owner->id,
            'creator_id' => $owner->id,
            'due_at' => now()->subMonth(),
        ]);

        TaskFollowUp::create([
            'task_id' => $task->id,
            'step' => FollowUpStep::Chase->value,
            'channel' => 'sms',
            'recipient_id' => $owner->id,
            'scheduled_at' => now()->subMonth(),
            'status' => FollowUpStatus::Pending,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('در صف اجرا')
            ->assertDontSee('ماه پیش');
    }
}
