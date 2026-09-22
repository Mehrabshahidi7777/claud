<?php

namespace App\Contracts;

use App\Payments\PaymentRequest;
use App\Payments\TokenResult;
use App\Payments\VerificationResult;

/**
 * The seam between billing and whichever bank is taking the money.
 *
 * Verification takes the expected amount rather than returning the bank's
 * figure for the caller to check. Making the comparison the gateway's job
 * means no caller can forget it — and forgetting it is how someone pays a
 * thousand Rial for a five million Rial plan.
 */
interface PaymentGateway
{
    /**
     * Ask the bank for a token against an amount. Returns null when the bank
     * refuses or cannot be reached; the caller shows the invoice again rather
     * than sending the payer to a broken redirect.
     */
    public function requestToken(PaymentRequest $request): ?TokenResult;

    /**
     * Confirm a reference server to server. Called after the payer returns,
     * and its verdict — not the callback's — decides whether anything is
     * marked paid.
     */
    public function verify(string $reference, int $expectedAmount): VerificationResult;
}
