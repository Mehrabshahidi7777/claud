<?php

namespace Tests\Feature;

use App\Sms\Drivers\AmootSmsDriver;
use App\Sms\PatternMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The wire format of a pattern send: values travel as one comma-separated
 * list in the pattern's variable order.
 */
class AmootSmsDriverTest extends TestCase
{
    public function test_values_are_sent_in_the_patterns_variable_order(): void
    {
        $this->sendChase('فاکتور پارس');

        Http::assertSent(fn (Request $request): bool => $request['PatternValues'] === 'مریم,فاکتور پارس'
            && $request['PatternCodeID'] === '1234');
    }

    public function test_a_comma_inside_a_value_does_not_shift_the_values_after_it(): void
    {
        $this->sendChase('خرید میز, صندلی');

        Http::assertSent(fn (Request $request): bool => $request['PatternValues'] === 'مریم,خرید میز، صندلی');
    }

    public function test_the_balance_is_asked_for_with_the_token(): void
    {
        Http::fake(['*' => Http::response(['RemainCount' => 4200])]);

        $credit = $this->driver()->credit();

        $this->assertSame(4200, $credit);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'CreditRemain')
            && $request['Token'] === 'token');
    }

    public function test_a_full_address_from_the_panel_is_used_as_it_is(): void
    {
        config(['sms.patterns.otp.code' => '77']);
        Http::fake(['*' => Http::response(['Status' => 'Success'])]);

        $driver = new AmootSmsDriver(Http::getFacadeRoot(), [
            'base_url' => 'https://portal.amootsms.com/rest',
            'token' => 'token',
            'line_number' => '',
            'endpoints' => ['send_pattern' => 'https://api.example.ir/v2/pattern/send'],
        ]);

        $driver->send(PatternMessage::make('989121110001', 'otp', ['code' => '12345']));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.example.ir/v2/pattern/send'
            && ! $request->hasHeader('LineNumber')
            && ! array_key_exists('LineNumber', $request->data()));
    }

    private function sendChase(string $title): void
    {
        config(['sms.patterns.chase.code' => '1234']);

        Http::fake(['*' => Http::response(['Status' => 'Success', 'MessageID' => 9])]);

        $result = $this->driver()->send(PatternMessage::make('989121110001', 'chase', [
            'name' => 'مریم',
            'title' => $title,
        ]));

        $this->assertTrue($result->successful);
    }

    private function driver(): AmootSmsDriver
    {
        return new AmootSmsDriver(Http::getFacadeRoot(), [
            'base_url' => 'https://portal.amootsms.com/rest',
            'token' => 'token',
            'line_number' => '5000',
            'endpoints' => ['send_pattern' => 'SendWithPattern', 'credit' => 'CreditRemain'],
        ]);
    }
}
