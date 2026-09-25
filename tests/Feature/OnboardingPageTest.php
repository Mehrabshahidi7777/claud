<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The first screen a new customer sees, and the one the platform owner uses to
 * open a workspace of their own. Neither has a workspace yet, so the shared
 * layout must render without one.
 */
class OnboardingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_customer_sees_the_sign_up_form(): void
    {
        $newcomer = User::factory()->create(['phone' => '09125550000', 'name' => '']);

        $this->actingAs($newcomer)->get(route('onboarding'))
            ->assertOk()
            ->assertSee('name="workspace"', false);
    }

    public function test_the_platform_owner_can_open_it_to_create_their_own_workspace(): void
    {
        config(['platform.admin_phones' => ['09134451502']]);
        $owner = User::factory()->create(['phone' => '09134451502', 'name' => '']);

        $this->actingAs($owner)->get(route('onboarding'))
            ->assertOk()
            ->assertSee(route('admin.dashboard'), false);
    }
}
