<?php

namespace Tests\Feature;

use App\Models\Sponsor;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The sponsors who keep پیگیر free: entered by the platform owner, shown on
 * the login page, the dashboard and their own page, and counted per click.
 */
class SponsorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Sponsor::LOGO_DISK);
        config(['platform.admin_phones' => ['09134451502']]);
        $this->admin = User::factory()->withPhone('989134451502')->create();
    }

    public function test_the_platform_owner_adds_a_sponsor_with_a_logo(): void
    {
        $this->actingAs($this->admin)->post(route('admin.sponsors.store'), [
            'name' => 'بیمه‌ی پارسیان',
            'description' => 'بیمه‌ی بدنه و شخص ثالث',
            'website_url' => 'https://example.ir',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'is_active' => '1',
        ])->assertRedirect(route('admin.sponsors.index'));

        $sponsor = Sponsor::firstOrFail();
        $this->assertSame('بیمه‌ی پارسیان', $sponsor->name);
        Storage::disk(Sponsor::LOGO_DISK)->assertExists($sponsor->logo_path);
    }

    public function test_an_svg_logo_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('admin.sponsors.store'), [
            'name' => 'اسپانسر',
            'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])->assertSessionHasErrors('logo');

        $this->assertSame(0, Sponsor::count());
    }

    public function test_nobody_else_can_reach_the_sponsor_admin(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.sponsors.index'))->assertNotFound();
    }

    public function test_replacing_and_deleting_a_logo_removes_the_old_file(): void
    {
        $sponsor = $this->sponsorWithLogo();
        $old = $sponsor->logo_path;

        $this->actingAs($this->admin)->put(route('admin.sponsors.update', $sponsor), [
            'name' => $sponsor->name,
            'logo' => UploadedFile::fake()->image('new.png'),
            'is_active' => '1',
        ])->assertRedirect();

        Storage::disk(Sponsor::LOGO_DISK)->assertMissing($old);
        $new = $sponsor->fresh()->logo_path;
        Storage::disk(Sponsor::LOGO_DISK)->assertExists($new);

        $this->actingAs($this->admin)->delete(route('admin.sponsors.destroy', $sponsor))->assertRedirect();

        Storage::disk(Sponsor::LOGO_DISK)->assertMissing($new);
        $this->assertSame(0, Sponsor::count());
    }

    public function test_the_login_page_shows_active_sponsors_only(): void
    {
        Sponsor::factory()->create(['name' => 'اسپانسر فعال']);
        Sponsor::factory()->inactive()->create(['name' => 'اسپانسر پنهان']);

        $this->get(route('login'))
            ->assertSee('اسپانسرهای پیگیر')
            ->assertSee('پیگیر به لطف اسپانسرها زنده است.')
            ->assertSee('اسپانسر فعال')
            ->assertDontSee('اسپانسر پنهان');
    }

    public function test_the_cached_list_survives_a_real_cache_store(): void
    {
        // The file and database stores serialize, and refuse to rebuild
        // objects; a cached model collection came back broken and took the
        // login page down with it.
        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');

        Sponsor::factory()->create(['name' => 'اسپانسر فعال']);

        $this->get(route('login'))->assertOk()->assertSee('اسپانسر فعال');
        $this->get(route('login'))->assertOk()->assertSee('اسپانسر فعال');
    }

    public function test_members_see_sponsors_on_the_dashboard_and_their_page(): void
    {
        Sponsor::factory()->create(['name' => 'اسپانسر فعال']);
        $workspace = Workspace::factory()->create();
        $member = User::factory()->create();
        $workspace->members()->attach($member, ['role' => 'member']);

        $this->actingAs($member)->get(route('dashboard'))->assertSee('اسپانسر فعال');
        // With the menu: a page without it strands the member there.
        $this->actingAs($member)->get(route('sponsors.index'))
            ->assertOk()
            ->assertSee('اسپانسر فعال')
            ->assertSee(route('tasks.index'), false);
    }

    public function test_a_click_is_counted_and_sent_to_the_sponsors_site(): void
    {
        $sponsor = Sponsor::factory()->create(['website_url' => 'https://www.example.ir/']);

        $this->get(route('login'))->assertSee('example.ir');
        $this->get(route('sponsors.visit', $sponsor))->assertRedirect('https://www.example.ir/');

        $this->assertSame(1, $sponsor->fresh()->clicks);
    }

    public function test_a_hidden_sponsor_does_not_redirect(): void
    {
        $sponsor = Sponsor::factory()->inactive()->create();

        $this->get(route('sponsors.visit', $sponsor))->assertNotFound();
    }

    public function test_the_logo_is_served_for_the_page_to_show(): void
    {
        $sponsor = $this->sponsorWithLogo();

        $this->get($sponsor->logoUrl())->assertOk();
    }

    private function sponsorWithLogo(): Sponsor
    {
        return Sponsor::factory()->create([
            'logo_path' => UploadedFile::fake()->image('logo.png')->store('sponsors', Sponsor::LOGO_DISK),
        ]);
    }
}
