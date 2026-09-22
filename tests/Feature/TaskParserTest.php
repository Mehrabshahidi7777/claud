<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Models\User;
use App\Models\Workspace;
use App\Services\TaskParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The model is never trusted. Every one of these tests is a way a language
 * model gets something wrong, and what the product does about it — because a
 * follow-up engine acting on a hallucinated deadline is worse than no engine.
 */
class TaskParserTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-22 09:00:00');

        $this->workspace = Workspace::factory()->create();

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

    public function test_it_turns_a_well_formed_answer_into_drafts(): void
    {
        $this->fakeModel(['tasks' => [
            ['title' => 'جلسه با آقای رضایی', 'assignee' => 'رضا مرادی', 'due_date' => '1405/07/20', 'priority' => 'high'],
        ]]);

        $result = app(TaskParser::class)->parse('فردا جلسه هست', $this->workspace);

        $this->assertTrue($result['used_ai']);
        $this->assertCount(1, $result['tasks']);
        $this->assertSame('جلسه با آقای رضایی', $result['tasks'][0]['title']);
        $this->assertSame('high', $result['tasks'][0]['priority']);
        $this->assertSame('رضا مرادی', $result['tasks'][0]['assignee_name']);
    }

    public function test_a_name_the_model_invented_resolves_to_nobody(): void
    {
        // Not to whoever it happens to resemble: assigning work to the wrong
        // person is worse than leaving it unassigned.
        $this->fakeModel(['tasks' => [
            ['title' => 'نصب کولر', 'assignee' => 'رضا مرادیان', 'due_date' => '', 'priority' => 'normal'],
        ]]);

        $result = app(TaskParser::class)->parse('نصب کولر', $this->workspace);

        $this->assertNull($result['tasks'][0]['assignee_id']);
    }

    public function test_a_date_in_the_past_is_dropped(): void
    {
        $this->fakeModel(['tasks' => [
            ['title' => 'کار', 'assignee' => '', 'due_date' => '1399/01/01', 'priority' => 'normal'],
        ]]);

        $result = app(TaskParser::class)->parse('کار', $this->workspace);

        $this->assertNull($result['tasks'][0]['due_date']);
    }

    public function test_a_date_absurdly_far_out_is_dropped(): void
    {
        $this->fakeModel(['tasks' => [
            ['title' => 'کار', 'assignee' => '', 'due_date' => '1420/01/01', 'priority' => 'normal'],
        ]]);

        $result = app(TaskParser::class)->parse('کار', $this->workspace);

        $this->assertNull($result['tasks'][0]['due_date']);
    }

    public function test_an_impossible_date_is_dropped(): void
    {
        $this->fakeModel(['tasks' => [
            ['title' => 'کار', 'assignee' => '', 'due_date' => '1405/13/40', 'priority' => 'normal'],
        ]]);

        $result = app(TaskParser::class)->parse('کار', $this->workspace);

        $this->assertNull($result['tasks'][0]['due_date']);
    }

    public function test_an_unknown_priority_falls_back_to_normal(): void
    {
        $this->fakeModel(['tasks' => [
            ['title' => 'کار', 'assignee' => '', 'due_date' => '', 'priority' => 'super-urgent'],
        ]]);

        $result = app(TaskParser::class)->parse('کار', $this->workspace);

        $this->assertSame('normal', $result['tasks'][0]['priority']);
    }

    public function test_an_entry_without_a_usable_title_is_thrown_away(): void
    {
        $this->fakeModel(['tasks' => [
            ['title' => 'ا', 'assignee' => '', 'due_date' => '', 'priority' => 'normal'],
            ['assignee' => 'رضا مرادی'],
            ['title' => 'نصب کولر گازی', 'assignee' => '', 'due_date' => '', 'priority' => 'normal'],
        ]]);

        $result = app(TaskParser::class)->parse('متن', $this->workspace);

        $this->assertCount(1, $result['tasks']);
        $this->assertSame('نصب کولر گازی', $result['tasks'][0]['title']);
    }

    public function test_a_wildly_overlong_answer_is_capped(): void
    {
        // A paragraph that yields more than ten tasks was misread.
        $this->fakeModel(['tasks' => array_fill(0, 40, [
            'title' => 'کار تکراری', 'assignee' => '', 'due_date' => '', 'priority' => 'normal',
        ])]);

        $result = app(TaskParser::class)->parse('متن', $this->workspace);

        $this->assertCount(10, $result['tasks']);
    }

    public function test_an_unreachable_model_falls_back_rather_than_failing(): void
    {
        $this->fakeModel(null);

        $result = app(TaskParser::class)->parse('متن', $this->workspace);

        $this->assertFalse($result['used_ai']);
        $this->assertSame([], $result['tasks']);
    }

    public function test_rubbish_instead_of_an_object_is_survived(): void
    {
        $this->fakeModel(['tasks' => ['just a string', 42, null]]);

        $result = app(TaskParser::class)->parse('متن', $this->workspace);

        $this->assertSame([], $result['tasks']);
    }

    public function test_the_endpoint_says_plainly_when_the_model_is_unavailable(): void
    {
        $this->fakeModel(null);

        $owner = User::factory()->create();
        $this->workspace->members()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->postJson(route('tasks.parse'), ['text' => 'فردا جلسه با آقای رضایی داریم'])
            ->assertStatus(503)
            ->assertJson(['ok' => false, 'reason' => 'unavailable']);
    }

    public function test_the_endpoint_returns_drafts_without_creating_anything(): void
    {
        $this->fakeModel(['tasks' => [
            ['title' => 'جلسه با آقای رضایی', 'assignee' => 'رضا مرادی', 'due_date' => '', 'priority' => 'normal'],
        ]]);

        $owner = User::factory()->create();
        $this->workspace->members()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->postJson(route('tasks.parse'), ['text' => 'فردا جلسه با آقای رضایی داریم'])
            ->assertOk()
            ->assertJsonPath('tasks.0.title', 'جلسه با آقای رضایی');

        // Nothing is written until the manager confirms a draft.
        $this->assertDatabaseCount('tasks', 0);
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
