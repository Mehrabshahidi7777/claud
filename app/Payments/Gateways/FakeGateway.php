<?php

namespace App\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Payments\PaymentRequest;
use App\Payments\TokenResult;
use App\Payments\VerificationResult;
use Illuminate\Support\Str;

/**
 * Moves no money. This is what the tests run against and what local
 * development should stay on — a real terminal settles real transactions, and
 * reversing one is a phone call to the bank.
 */
class FakeGateway implements PaymentGateway
{
    /** @var list<PaymentRequest> */
    public array $requests = [];

    private bool $refuseToken = false;

    private ?string $refuseVerification = null;

    /** Pretend the bank settled a different amount than we asked for. */
    private ?int $settleAmount = null;

    public function requestToken(PaymentRequest $request): ?TokenResult
    {
        $this->requests[] = $request;

        if ($this->refuseToken) {
            return null;
        }

        $token = 'fake-'.Str::random(24);

        return new TokenResult($token, 'https://gateway.test/pay?token='.$token);
    }

    public function verify(string $reference, int $expectedAmount): VerificationResult
    {
        if ($this->refuseVerification !== null) {
            return VerificationResult::refused($this->refuseVerification);
        }

        $settled = $this->settleAmount ?? $expectedAmount;

        // The real gateway makes this comparison itself so no caller can skip
        // it; the fake mirrors that, which is what lets the mismatch case be
        // tested at all.
        if ($settled !== $expectedAmount) {
            return VerificationResult::refused('amount mismatch');
        }

        return VerificationResult::settled($settled, ['fake' => true, 'reference' => $reference]);
    }

    public function refuseTokens(): self
    {
        $this->refuseToken = true;

        return $this;
    }

    public function refuseVerification(string $reason = 'gateway refused'): self
    {
        $this->refuseVerification = $reason;

        return $this;
    }

    /**
     * The tampering case: the payer reached the gateway but paid a different
     * amount than the invoice says.
     */
    public function settlesAmount(int $amount): self
    {
        $this->settleAmount = $amount;

        return $this;
    }
}
