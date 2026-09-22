<?php

namespace App\Payments;

final class VerificationResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly bool $successful,
        public readonly ?int $amount = null,
        public readonly ?string $reason = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function settled(int $amount, array $raw = []): self
    {
        return new self(true, $amount, null, $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function refused(string $reason, array $raw = []): self
    {
        return new self(false, null, $reason, $raw);
    }
}
