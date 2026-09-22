<?php

namespace App\Payments;

final class TokenResult
{
    public function __construct(
        public readonly string $token,
        public readonly string $redirectUrl,
    ) {}
}
