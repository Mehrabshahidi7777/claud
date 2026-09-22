<?php

namespace App\Sms;

use App\Support\PersianText;
use InvalidArgumentException;

/**
 * One outbound message, expressed the only way a dedicated line accepts it:
 * a registered pattern plus its values. There is deliberately no way to build
 * one of these from free text — the line would reject it, and discovering that
 * at send time is worse than discovering it here.
 */
final class PatternMessage
{
    /**
     * @param  array<string, string>  $tokens
     */
    private function __construct(
        public readonly string $phone,
        public readonly string $key,
        public readonly ?string $code,
        public readonly array $tokens,
        public readonly string $preview,
    ) {}

    /**
     * @param  array<string, string|int|null>  $tokens
     */
    public static function make(string $phone, string $key, array $tokens = []): self
    {
        $pattern = config("sms.patterns.$key");

        if ($pattern === null) {
            throw new InvalidArgumentException("Unknown SMS pattern [$key].");
        }

        $tokens = array_map(static fn ($value) => (string) $value, $tokens);

        self::assertTokensMatch($key, $pattern['tokens'] ?? [], $tokens);

        return new self(
            phone: $phone,
            key: $key,
            code: $pattern['code'] ?? null,
            tokens: $tokens,
            preview: self::render($pattern['preview'] ?? '', $tokens),
        );
    }

    /**
     * The values in the order the registered pattern expects them. Providers
     * that take a positional list rather than a map rely on this ordering, so
     * it follows the config rather than whatever order the caller passed.
     *
     * @return list<string>
     */
    public function orderedValues(): array
    {
        $names = config("sms.patterns.$this->key.tokens", []);

        return array_values(array_map(
            fn (string $name) => $this->tokens[$name] ?? '',
            $names,
        ));
    }

    /**
     * What this message bills as. Computed from the rendered preview because
     * the pattern's own text is what the recipient receives, and its length is
     * what the provider charges for.
     */
    public function segments(): int
    {
        return PersianText::segments($this->preview);
    }

    public function isConfigured(): bool
    {
        return filled($this->code);
    }

    /**
     * @param  list<string>  $expected
     * @param  array<string, string>  $given
     */
    private static function assertTokensMatch(string $key, array $expected, array $given): void
    {
        $missing = array_diff($expected, array_keys($given));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                "SMS pattern [$key] is missing token(s): ".implode(', ', $missing).'.',
            );
        }
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private static function render(string $template, array $tokens): string
    {
        foreach ($tokens as $name => $value) {
            $template = str_replace('{'.$name.'}', $value, $template);
        }

        return $template;
    }
}
