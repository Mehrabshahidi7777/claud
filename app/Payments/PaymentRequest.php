<?php

namespace App\Payments;

/**
 * What the bank is asked for. Amount is in Rial, the unit the gateway works
 * in — nothing here ever sees Toman.
 */
final class PaymentRequest
{
    public function __construct(
        public readonly int $amount,
        public readonly string $reference,
        public readonly string $callbackUrl,
        public readonly ?string $payerPhone = null,
    ) {}
}
