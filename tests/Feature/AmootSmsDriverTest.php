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

    private function sendChase(string $title): void
    {
        config(['sms.patterns.chase.code' => '1234']);

        Http::fake(['*' => Http::response(['Status' => 'Success', 'MessageID' => 9])]);

        $driver = new AmootSmsDriver(Http::getFacadeRoot(), [
            'base_url' => 'https://portal.amootsms.com/rest',
            'token' => 'token',
            'line_number' => '5000',
            'endpoints' => ['send_pattern' => 'SendWithPattern'],
        ]);

        $result = $driver->send(PatternMessage::make('989121110001', 'chase', [
            'name' => 'مریم',
            'title' => $title,
        ]));

        $this->assertTrue($result->successful);
    }
}
