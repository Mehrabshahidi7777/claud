<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The first-days checklist on the dashboard: shown to whoever set the
 * workspace up, ticked off by what actually exists, gone once it is done.
 */
class GettingStartedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_owner_sees_the_checklist_with_nothing_done(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('شروع کار با')
            ->assertSee('اولین کار را ثبت کنید')
            ->assertSee('یک همکار اضافه کنید')
            ->assertSee('یک کار تکراری بسازید');
    }

    public function test_a_friends_workspace_is_asked_for_a_shared_expense_instead(): void
    {
        [, $owner] = $this->workspaceWithOwner(WorkspaceType::Friends);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertSee('یک دوست اضافه کنید')
            ->assertSee('اولین خرج مشترک را ثبت کنید')
            ->assertDontSee('یک کار تکراری بسازید');
    }

    public function test_it_disappears_once_every_step_is_done(): void
    {
        [$workspace, $owner] = $this->workspaceWithOwner();

        $colleague = User::factory()->create();
        $workspace->members()->attach($colleague, ['role' => 'member']);
        Task::factory()->for($workspace)->create(['creator_id' => $owner->id, 'assignee_id' => $colleague->id]);
        RecurringTask::factory()->for($workspace)->create();

        $this->actingAs($owner)->get(route('dashboard'))->assertDontSee('شروع کار با');
    }

    public function test_the_owner_can_hide_it(): void
    {
        [, $owner] = $this->workspaceWithOwner();

        $this->actingAs($owner)->post(route('getting-started.dismiss'))->assertRedirect(route('dashboard'));

        $this->actingAs($owner)->get(route('dashboard'))->assertDontSee('شروع کار با');
    }

    public function test_an_invited_member_neither_sees_nor_hides_it(): void
    {
        [$workspace] = $this->workspaceWithOwner();
        $member = User::factory()->create();
        $workspace->members()->attach($member, ['role' => 'member']);

        $this->actingAs($member)->get(route('dashboard'))->assertDontSee('شروع کار با');
        $this->actingAs($member)->post(route('getting-started.dismiss'))->assertForbidden();
    }

    /**
     * @return array{Workspace, User}
     */
    private function workspaceWithOwner(WorkspaceType $type = WorkspaceType::Corporate): array
    {
        $workspace = Workspace::factory()->type($type)->create();
        $owner = User::factory()->create();
        $workspace->members()->attach($owner, ['role' => 'owner']);

        return [$workspace, $owner];
    }
}
