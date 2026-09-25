<?php

namespace Tests\Feature;

use App\Contracts\SmsDriver;
use App\Models\OtpCode;
use App\Models\User;
use App\Models\Workspace;
use App\Sms\Drivers\FakeSmsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsDriver $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsDriver;
        $this->app->instance(SmsDriver::class, $this->sms);

        config([
            'sms.patterns.otp.code' => 'P-OTP',
            'sms.patterns.welcome.code' => 'P-WELCOME',
        ]);

        RateLimiter::clear('otp:phone:989121234567');
    }

    public function test_it_texts_a_code_for_a_valid_number(): void
    {
        $this->post(route('login.request'), ['phone' => '09121234567'])
            ->assertRedirect(route('login.code'));

        $this->sms->assertSent('otp', '989121234567');
        $this->assertDatabaseCount('otp_codes', 1);
    }

    public function test_a_refused_send_is_reported_rather_than_leaving_someone_on_the_code_screen(): void
    {
        // What a brand-new dedicated line does on day one: the pattern is not
        // approved yet, or the token is wrong, or there is no credit. Saying
        // "کد فرستاده شد" then is the worst possible answer.
        $this->sms->failEverything('pattern not approved');

        $this->post(route('login.request'), ['phone' => '09121234567'])
            ->assertSessionHasErrors('phone');

        $this->assertSame(
            'پیامک ارسال نشد. دوباره تلاش کنید و اگر تکرار شد با پشتیبانی تماس بگیرید.',
            session('errors')->first('phone'),
        );

        // And the minute-long throttle is released, because it was spent on a
        // code that never arrived.
        $this->assertFalse(RateLimiter::tooManyAttempts('otp:phone:989121234567', 1));
    }

    public function test_it_refuses_a_number_that_is_not_a_mobile(): void
    {
        $this->post(route('login.request'), ['phone' => '02188776655'])
            ->assertSessionHasErrors('phone');

        $this->sms->assertNothingSent();
    }

    public function test_the_code_is_stored_hashed_never_in_the_clear(): void
    {
        $this->post(route('login.request'), ['phone' => '09121234567']);

        $stored = OtpCode::first()->code_hash;

        $this->assertNotEmpty($stored);
        $this->assertStringNotContainsString(' ', $stored);
        // A hash, not five digits sitting in a column.
        $this->assertGreaterThan(20, strlen($stored));
    }

    public function test_a_correct_code_signs_a_new_person_in_and_sends_them_to_onboarding(): void
    {
        $code = $this->requestCodeAndCapture('989121234567');

        $this->withSession(['otp_phone' => '989121234567'])
            ->post(route('login.verify'), ['code' => $code])
            ->assertRedirect(route('onboarding'));

        $this->assertAuthenticated();
        $this->assertNotNull(User::where('phone', '989121234567')->first()->phone_verified_at);
    }

    public function test_persian_digits_in_the_code_are_accepted(): void
    {
        $code = $this->requestCodeAndCapture('989121234567');

        $persian = strtr($code, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);

        $this->withSession(['otp_phone' => '989121234567'])
            ->post(route('login.verify'), ['code' => $persian])
            ->assertRedirect(route('onboarding'));

        $this->assertAuthenticated();
    }

    public function test_a_member_created_by_their_manager_signs_into_that_account(): void
    {
        // The case the whole design turns on: they exist, they are assignable,
        // and they have never signed in.
        $workspace = Workspace::factory()->create();
        $member = User::factory()->unverified()->withPhone('989121234567')->create(['name' => 'رضا مرادی']);
        $workspace->members()->attach($member, ['role' => 'member']);

        $code = $this->requestCodeAndCapture('989121234567');

        $this->withSession(['otp_phone' => '989121234567'])
            ->post(route('login.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($member->fresh());
        $this->assertSame(1, User::where('phone', '989121234567')->count());
    }

    public function test_a_wrong_code_is_rejected(): void
    {
        $this->requestCodeAndCapture('989121234567');

        $this->withSession(['otp_phone' => '989121234567'])
            ->post(route('login.verify'), ['code' => '00000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_three_wrong_guesses_burn_the_code(): void
    {
        $code = $this->requestCodeAndCapture('989121234567');

        for ($i = 0; $i < 3; $i++) {
            $this->withSession(['otp_phone' => '989121234567'])
                ->post(route('login.verify'), ['code' => '00000']);
        }

        // Even the right code no longer works.
        $this->withSession(['otp_phone' => '989121234567'])
            ->post(route('login.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_guesses_sent_in_parallel_cannot_take_more_than_three_attempts(): void
    {
        $this->requestCodeAndCapture('989121234567');

        // One request loaded the row; three others used every attempt in the
        // meantime. The stale copy must not get a fourth.
        $stale = OtpCode::first();
        OtpCode::whereKey($stale->id)->update(['attempts' => OtpCode::MAX_ATTEMPTS]);

        $this->assertFalse($stale->claimAttempt());
    }

    public function test_a_code_signs_in_only_once_even_when_submitted_twice_at_once(): void
    {
        $this->requestCodeAndCapture('989121234567');

        $first = OtpCode::first();
        $second = OtpCode::first();

        $this->assertTrue($first->consume());
        $this->assertFalse($second->consume());
    }

    public function test_one_number_gets_at_most_ten_codes_a_day(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->clearShortThrottles();

            $this->post(route('login.request'), ['phone' => '09121234567'])
                ->assertRedirect(route('login.code'));
        }

        $this->clearShortThrottles();

        $this->post(route('login.request'), ['phone' => '09121234567'])
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('otp_codes', 10);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $code = $this->requestCodeAndCapture('989121234567');

        OtpCode::first()->update(['expires_at' => now()->subMinute()]);

        $this->withSession(['otp_phone' => '989121234567'])
            ->post(route('login.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');
    }

    public function test_a_used_code_cannot_be_replayed(): void
    {
        $code = $this->requestCodeAndCapture('989121234567');

        $this->withSession(['otp_phone' => '989121234567'])
            ->post(route('login.verify'), ['code' => $code]);

        $this->assertNotNull(
            OtpCode::first()->consumed_at,
            'A code that signed someone in must be consumed immediately.',
        );

        $this->post(route('logout'));

        // The guard caches the resolved user within a test, so it is reset
        // explicitly — otherwise the guest middleware redirects the replay
        // before the controller ever sees it and the test proves nothing.
        $this->app['auth']->forgetGuards();

        $this->withSession(['otp_phone' => '989121234567'])
            ->post(route('login.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_asking_again_immediately_is_throttled(): void
    {
        // Each code is a real SMS we pay for; without this a script empties
        // the credit overnight.
        $this->post(route('login.request'), ['phone' => '09121234567']);

        $this->post(route('login.request'), ['phone' => '09121234567'])
            ->assertSessionHasErrors('phone');

        $this->sms->assertSentCount(1);
    }

    public function test_requesting_a_new_code_retires_the_previous_one(): void
    {
        $first = $this->requestCodeAndCapture('989121234567');

        RateLimiter::clear('otp:phone:989121234567');
        $this->post(route('login.request'), ['phone' => '09121234567']);

        $this->withSession(['otp_phone' => '989121234567'])
            ->post(route('login.verify'), ['code' => $first])
            ->assertSessionHasErrors('code');
    }

    public function test_the_code_screen_is_unreachable_without_a_number_in_session(): void
    {
        $this->get(route('login.code'))->assertRedirect(route('login'));
    }

    public function test_onboarding_creates_the_workspace_and_makes_them_its_owner(): void
    {
        $user = User::factory()->create(['name' => '']);

        $this->actingAs($user)
            ->post(route('onboarding.store'), [
                'name' => 'مهراب شهیدی',
                'workspace' => 'تأسیسات پارس',
                'type' => 'corporate',
            ])
            ->assertRedirect(route('dashboard'));

        $workspace = Workspace::where('name', 'تأسیسات پارس')->first();

        $this->assertNotNull($workspace);
        $this->assertSame('owner', $workspace->members()->first()->pivot->role);
        $this->assertSame('مهراب شهیدی', $user->fresh()->name);
    }

    public function test_onboarding_cannot_be_posted_again_for_another_free_trial(): void
    {
        $user = User::factory()->create(['name' => 'مهراب شهیدی']);
        Workspace::factory()->create()->members()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->post(route('onboarding.store'), [
                'name' => 'مهراب شهیدی',
                'workspace' => 'یک شرکت دیگر',
                'type' => 'corporate',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('workspaces', ['name' => 'یک شرکت دیگر']);
    }

    /**
     * The fake driver records the PatternMessage, so the code that was texted
     * is readable without reaching into the hash.
     */
    private function requestCodeAndCapture(string $phone): string
    {
        $this->post(route('login.request'), ['phone' => $phone]);

        $message = collect($this->sms->sent)->last(fn ($m) => $m->key === 'otp');

        $code = $message->tokens['code'];

        // Guard the helper itself: a silent change here would make several
        // tests pass for the wrong reason.
        $this->assertTrue(Hash::check($code, OtpCode::latest('id')->first()->code_hash));

        return $code;
    }

    private function clearShortThrottles(): void
    {
        RateLimiter::clear('otp:phone:989121234567');
        RateLimiter::clear('otp:phone-hourly:989121234567');
        RateLimiter::clear('otp:ip:127.0.0.1');
    }
}
