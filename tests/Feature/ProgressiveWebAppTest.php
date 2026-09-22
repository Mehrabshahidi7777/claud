<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The install exists for the field technician, who opens the app in a plant
 * room on one bar of signal. These check the three things that decide whether
 * it installs at all, plus the one behaviour that would make it dangerous.
 *
 * The manifest, worker and offline page are static files served by the web
 * server rather than the router, so they are read from disk. Asserting over
 * HTTP would only prove that PHPUnit's client does not serve static files.
 */
class ProgressiveWebAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_is_served_and_declares_what_an_install_needs(): void
    {
        $manifest = $this->manifest();

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('rtl', $manifest['dir']);
        $this->assertSame('fa', $manifest['lang']);

        // Android crops a maskable icon into the launcher's shape; without
        // one the icon arrives with its corners cut off.
        $purposes = array_column($manifest['icons'], 'purpose');
        $this->assertContains('maskable', $purposes);

        $sizes = array_column($manifest['icons'], 'sizes');
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
    }

    public function test_the_manifest_opens_on_the_screen_a_technician_needs(): void
    {
        $manifest = $this->manifest();

        // Not the full task list: their own work is the only thing they came
        // for, and one tap saved matters on a phone in a basement.
        $this->assertSame('/tasks?filter=mine', $manifest['start_url']);
    }

    public function test_every_declared_icon_actually_exists(): void
    {
        $manifest = $this->manifest();

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path($icon['src']), "Missing icon: {$icon['src']}");
        }
    }

    public function test_the_service_worker_and_offline_page_exist(): void
    {
        $this->assertFileExists(public_path('sw.js'));
        $this->assertStringContainsString(
            'اتصال اینترنت برقرار نیست',
            file_get_contents(public_path('offline.html')),
        );
    }

    public function test_the_service_worker_never_caches_task_state(): void
    {
        // A cached task list tells someone work is still open when they closed
        // it an hour ago, which is worse than showing them nothing.
        $worker = file_get_contents(public_path('sw.js'));

        $this->assertStringNotContainsString("'/tasks'", $worker);
        $this->assertStringNotContainsString('"/tasks"', $worker);
    }

    public function test_the_service_worker_leaves_writes_alone(): void
    {
        // Quietly swallowing a "done" the technician just pressed is the worst
        // thing an offline layer could do, so non-GET requests pass straight
        // through and fail loudly when the network is gone.
        $worker = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("request.method !== 'GET'", $worker);
    }

    public function test_the_pages_declare_the_manifest(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $workspace->members()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertSee('manifest.webmanifest', false)
            ->assertSee('apple-touch-icon', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
    }
}
