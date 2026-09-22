<?php

namespace App\Sms;

final class SmsResult
{
    private function __construct(
        public readonly bool $successful,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
    ) {}

    public static function sent(?string $providerMessageId = null): self
    {
        return new self(true, $providerMessageId);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
