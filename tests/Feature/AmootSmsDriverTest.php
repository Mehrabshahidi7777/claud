<?php

namespace Tests\Feature;

use App\Sms\Drivers\AmootSmsDriver;
use App\Sms\PatternMessage;
use App\Sms\SmsResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The wire format of a pattern send, as Amoot's REST reference describes it:
 * SendWithPatternOWN on a dedicated line, values as one comma-separated list
 * in the pattern's variable order.
 */
class AmootSmsDriverTest extends TestCase
{
    public function test_a_dedicated_line_sends_through_send_with_pattern_own(): void
    {
        $this->sendChase('فاکتور پارس');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://portal.amootsms.com/rest/SendWithPatternOWN'
            && $request['LineNumber'] === '9830005088'
            && $request['PatternCodeID'] === '1234'
            && $request['PatternValues'] === 'مریم,فاکتور پارس'
            && $request['Token'] === 'token'
            && $request->header('Authorization') === ['token']);
    }

    public function test_without_a_line_number_it_uses_the_shared_pattern_line(): void
    {
        config(['sms.patterns.otp.code' => '77']);
        Http::fake(['*' => Http::response(['Status' => 'Success'])]);

        $this->driver(['line_number' => ''])->send(PatternMessage::make('989121110001', 'otp', ['code' => '12345']));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://portal.amootsms.com/rest/SendWithPattern'
            && ! array_key_exists('LineNumber', $request->data()));
    }

    public function test_a_comma_inside_a_value_does_not_shift_the_values_after_it(): void
    {
        $this->sendChase('خرید میز, صندلی');

        Http::assertSent(fn (Request $request): bool => $request['PatternValues'] === 'مریم,خرید میز، صندلی');
    }

    public function test_the_message_id_is_read_from_the_data_rows(): void
    {
        $result = $this->sendChase('فاکتور پارس', ['Status' => 'Success', 'Data' => [['Status' => 'Success', 'MessageID' => 5521, 'Mobile' => '989121110001']]]);

        $this->assertTrue($result->successful);
        $this->assertSame('5521', $result->providerMessageId);
    }

    public function test_a_refusal_is_a_failure_with_the_panels_answer_kept(): void
    {
        $result = $this->sendChase('فاکتور پارس', ['Status' => 'InvalidPatternCode']);

        $this->assertFalse($result->successful);
        $this->assertStringContainsString('InvalidPatternCode', $result->error);
    }

    public function test_a_full_address_from_the_panel_is_used_as_it_is(): void
    {
        config(['sms.patterns.otp.code' => '77']);
        Http::fake(['*' => Http::response(['Status' => 'Success'])]);

        $this->driver(['endpoints' => ['send_pattern' => 'https://api.example.ir/v2/pattern/send']])
            ->send(PatternMessage::make('989121110001', 'otp', ['code' => '12345']));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.example.ir/v2/pattern/send');
    }

    public function test_the_balance_comes_from_account_status(): void
    {
        Http::fake(['*' => Http::response(['Status' => 'Success', 'Data' => ['AccountName' => 'پیگیر', 'RemaindCredit' => 4200]])]);

        $this->assertSame(4200, $this->driver()->credit());

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://portal.amootsms.com/rest/AccountStatus')
            && $request['Token'] === 'token');
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function sendChase(string $title, array $response = ['Status' => 'Success']): SmsResult
    {
        config(['sms.patterns.chase.code' => '1234']);

        Http::fake(['*' => Http::response($response)]);

        return $this->driver()->send(PatternMessage::make('989121110001', 'chase', [
            'name' => 'مریم',
            'title' => $title,
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function driver(array $overrides = []): AmootSmsDriver
    {
        return new AmootSmsDriver(Http::getFacadeRoot(), array_merge([
            'base_url' => 'https://portal.amootsms.com/rest',
            'token' => 'token',
            'line_number' => '9830005088',
            'endpoints' => ['send_pattern' => null, 'credit' => null],
        ], $overrides));
    }
}
