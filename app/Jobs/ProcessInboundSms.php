<?php

namespace App\Jobs;

use App\Services\InboundProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInboundSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        private readonly string $fromPhone,
        private readonly string $body,
        private readonly string $providerMessageId,
        private readonly array $raw = [],
    ) {}

    public function handle(InboundProcessor $processor): void
    {
        try {
            $processor->process($this->fromPhone, $this->body, $this->providerMessageId, $this->raw);
        } catch (UniqueConstraintViolationException) {
            // The provider redelivered a message we already handled. The unique
            // index on provider_message_id is what makes that harmless, and
            // hitting it here means it did its job.
        }
    }

    /**
     * Replies are time sensitive — someone is waiting to see their task close
     * — so retries are quick rather than backed off over minutes.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30];
    }
}
