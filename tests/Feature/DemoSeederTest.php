<?php

namespace Tests\Feature;

use App\Models\Workspace;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo company has made-up phone numbers. On the live server the
 * follow-up engine would text them, so the seeder refuses to run there.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // Run directly: db:seed itself would stop to ask "are you sure?".
        $this->app->make(DemoSeeder::class)->run();

        $this->assertSame(0, Workspace::count());
    }

    public function test_it_builds_the_demo_everywhere_else(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertTrue(Workspace::where('name', 'تأسیسات پارس')->exists());
    }
}
