<?php

namespace App\Sms\Drivers;

use App\Contracts\SmsDriver;
use App\Sms\PatternMessage;
use App\Sms\SmsResult;
use PHPUnit\Framework\Assert;

/**
 * Records messages instead of sending them. The whole engine can be exercised
 * against this without a panel, a line, or an approved pattern — which is what
 * makes it possible to build the ladder before the dedicated line is issued.
 */
class FakeSmsDriver implements SmsDriver
{
    /** @var list<PatternMessage> */
    public array $sent = [];

    private ?string $failWith = null;

    public function send(PatternMessage $message): SmsResult
    {
        if ($this->failWith !== null) {
            return SmsResult::failed($this->failWith);
        }

        $this->sent[] = $message;

        return SmsResult::sent('fake-'.count($this->sent));
    }

    public function credit(): ?int
    {
        return 1000;
    }

    /**
     * Makes every subsequent send fail, so retry and failure paths can be
     * tested without waiting for a real outage.
     */
    public function failEverything(string $error = 'forced failure'): self
    {
        $this->failWith = $error;

        return $this;
    }

    public function assertSent(string $patternKey, ?string $phone = null): void
    {
        $matches = array_filter(
            $this->sent,
            fn (PatternMessage $m) => $m->key === $patternKey
                && ($phone === null || $m->phone === $phone),
        );

        Assert::assertNotEmpty(
            $matches,
            "Expected an SMS with pattern [$patternKey]".($phone ? " to [$phone]" : '').', none was sent.',
        );
    }

    public function assertNotSent(string $patternKey): void
    {
        $matches = array_filter($this->sent, fn (PatternMessage $m) => $m->key === $patternKey);

        Assert::assertEmpty($matches, "Expected no SMS with pattern [$patternKey], one was sent.");
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->sent);
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty($this->sent, 'Expected no SMS to be sent.');
    }
}
