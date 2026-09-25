<?php

namespace Tests\Feature;

use App\Sms\PatternMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pattern texts in config/sms.php are what gets pasted into the Amoot
 * panel, and the panel fills them from one positional list of values.
 */
class SmsPatternRegistryTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function patternKeys(): array
    {
        $config = require dirname(__DIR__, 2).'/config/sms.php';

        return collect(array_keys($config['patterns']))
            ->mapWithKeys(fn (string $key): array => [$key => [$key]])
            ->all();
    }

    #[DataProvider('patternKeys')]
    public function test_variables_are_written_the_way_amoot_expects(string $key): void
    {
        $preview = config("sms.patterns.$key.preview");

        $this->assertDoesNotMatchRegularExpression('/[{}]/', $preview, "[$key] uses braces; Amoot variables are %name%.");
    }

    #[DataProvider('patternKeys')]
    public function test_tokens_are_listed_in_the_order_they_appear_in_the_text(string $key): void
    {
        preg_match_all('/%([a-z_]+)%/', config("sms.patterns.$key.preview"), $matches);

        $this->assertSame(
            $matches[1],
            config("sms.patterns.$key.tokens"),
            "[$key] would send its values in a different order than the panel reads them.",
        );
    }

    #[DataProvider('patternKeys')]
    public function test_every_pattern_has_a_variable_because_amoot_refuses_one_without(string $key): void
    {
        $this->assertNotEmpty(config("sms.patterns.$key.tokens"), "[$key] has no variable; the panel will not register it.");
    }

    public function test_a_rendered_message_has_no_variables_left_in_it(): void
    {
        $message = PatternMessage::make('989121110001', 'subscription_expiring', [
            'plan' => 'خانوادگی',
            'days' => 3,
        ]);

        $this->assertSame(['خانوادگی', '3'], $message->orderedValues());
        $this->assertStringContainsString('اشتراک خانوادگی تا 3 روز دیگر', $message->preview);
    }
}
