<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding a task by what it is called or who has it, and taking the same list
 * away as a spreadsheet — never more of it than the person may see.
 */
class TaskSearchAndExportTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->owner = User::factory()->create(['name' => 'مهراب شهیدی']);
        $this->member = User::factory()->create(['name' => 'رضا مرادی']);
        $this->workspace->members()->attach($this->owner, ['role' => 'owner']);
        $this->workspace->members()->attach($this->member, ['role' => 'member']);

        $this->task('تعمیر پمپ آب', $this->member);
        $this->task('تمدید بیمه‌ی ماشین', $this->owner);
    }

    public function test_search_matches_the_title(): void
    {
        $this->actingAs($this->owner)->get(route('tasks.index', ['q' => 'پمپ']))
            ->assertSee('تعمیر پمپ آب')
            ->assertDontSee('تمدید بیمه‌ی ماشین');
    }

    public function test_search_matches_the_assignee_and_folds_an_arabic_keyboard(): void
    {
        // «ي» as an Arabic keyboard types it, for the «ی» in رضا مرادی.
        $this->actingAs($this->owner)->get(route('tasks.index', ['q' => 'مرادي']))
            ->assertSee('تعمیر پمپ آب')
            ->assertDontSee('تمدید بیمه‌ی ماشین');
    }

    public function test_the_export_is_the_list_as_a_spreadsheet_excel_can_read(): void
    {
        $response = $this->actingAs($this->owner)->get(route('tasks.export'));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('تعمیر پمپ آب', $csv);
        $this->assertStringContainsString('تمدید بیمه‌ی ماشین', $csv);
    }

    public function test_a_member_exports_only_the_work_they_are_part_of(): void
    {
        $csv = $this->actingAs($this->member)->get(route('tasks.export'))->streamedContent();

        $this->assertStringContainsString('تعمیر پمپ آب', $csv);
        $this->assertStringNotContainsString('تمدید بیمه‌ی ماشین', $csv);
    }

    public function test_a_title_that_looks_like_a_formula_is_exported_as_text(): void
    {
        $this->task('=HYPERLINK("http://evil.example","کلیک")', $this->owner);

        $csv = $this->actingAs($this->owner)->get(route('tasks.export'))->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    private function task(string $title, User $assignee): Task
    {
        return Task::factory()->for($this->workspace)->create([
            'title' => $title,
            'assignee_id' => $assignee->id,
            'creator_id' => $this->owner->id,
        ]);
    }
}
