<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The command exists to be trusted on a server nobody has logged into for a
 * month, so the one thing it must never do is report green over a setup that
 * cannot send.
 */
class CheckSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_live_line_with_no_credentials_fails_the_check(): void
    {
        config([
            'sms.driver' => 'amoot',
            'sms.amoot.token' => null,
            'sms.amoot.line_number' => null,
            'sms.patterns.otp.code' => null,
        ]);

        $this->artisan('app:check')->assertFailed();
    }

    public function test_a_missing_sign_in_pattern_only_blocks_once_the_line_is_live(): void
    {
        // On the log driver nothing is sent either way, so an unregistered
        // pattern is a note for later rather than an outage.
        config(['sms.driver' => 'log', 'sms.patterns.otp.code' => null]);

        $this->artisan('app:check')->assertSuccessful();
    }

    public function test_a_fully_configured_line_passes(): void
    {
        config([
            'sms.driver' => 'amoot',
            'sms.amoot.token' => 'token-from-panel',
            'sms.amoot.line_number' => '30002100',
            'sms.amoot.inbound_secret' => str_repeat('a', 48),
        ]);

        foreach (array_keys(config('sms.patterns')) as $key) {
            config(["sms.patterns.$key.code" => 'P-'.strtoupper($key)]);
        }

        $this->artisan('app:check')->assertSuccessful();
    }

    public function test_a_live_gateway_with_an_http_callback_fails(): void
    {
        // Saman rejects a plaintext callback, and discovering that with a
        // customer's money mid-flight is the expensive way to find out.
        config([
            'payment.gateway' => 'sep',
            'payment.sep.terminal_id' => '12345678',
            'app.url' => 'http://example.ir',
        ]);

        $this->artisan('app:check')->assertFailed();
    }
}
