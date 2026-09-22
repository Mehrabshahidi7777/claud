<?php

namespace App\Sms\Drivers;

use App\Contracts\SmsDriver;
use App\Sms\PatternMessage;
use App\Sms\SmsResult;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * AmootSMS over a dedicated line, which only accepts pattern-based sends.
 *
 * The wire format is confined to payloadFor() and interpret() on purpose:
 * parameter names differ between panel generations, so confirm both against
 * the API documentation in your own panel before the first live send. Nothing
 * outside this class needs to change when they do.
 */
class AmootSmsDriver implements SmsDriver
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
    ) {}

    public function send(PatternMessage $message): SmsResult
    {
        if (! $message->isConfigured()) {
            return SmsResult::failed(
                "Pattern [$message->key] has no code configured. Register it in the panel and set its environment variable.",
            );
        }

        try {
            $response = $this->request()->asForm()->post(
                $this->endpoint('send_pattern'),
                $this->payloadFor($message),
            );
        } catch (Throwable $e) {
            // A network failure is not a delivery failure. The caller leaves
            // the rung pending so the next sweep tries again.
            return SmsResult::failed('transport: '.$e->getMessage());
        }

        if ($response->failed()) {
            return SmsResult::failed("http {$response->status()}: ".$response->body());
        }

        return $this->interpret($response->json() ?? []);
    }

    public function credit(): ?int
    {
        try {
            $response = $this->request()->get($this->endpoint('credit'));
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $body = $response->json() ?? [];

        $value = $body['RemainCount'] ?? $body['Credit'] ?? $body['credit'] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * VERIFY AGAINST YOUR PANEL. Both the positional list and the named map
     * are sent because panels differ in which they read; the unused one is
     * ignored rather than rejected.
     *
     * @return array<string, scalar>
     */
    protected function payloadFor(PatternMessage $message): array
    {
        $values = $message->orderedValues();

        return array_filter([
            'Token' => $this->config['token'] ?? null,
            'Mobile' => $message->phone,
            'PatternCodeID' => $message->code,
            'PatternValues' => implode(',', $values),
            'SendDateTime' => '',
            'LineNumber' => $this->config['line_number'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * VERIFY AGAINST YOUR PANEL. Amoot signals success with a status field
     * whose name has varied; anything unrecognised is treated as a failure so
     * a silently dropped message is never recorded as delivered.
     *
     * @param  array<string, mixed>  $body
     */
    protected function interpret(array $body): SmsResult
    {
        $status = $body['Status'] ?? $body['status'] ?? null;
        $messageId = $body['MessageID'] ?? $body['MessageId'] ?? $body['RefID'] ?? null;

        $successful = in_array(
            is_string($status) ? strtolower($status) : $status,
            ['success', 'ok', 0, '0', 1, '1', true],
            true,
        );

        if (! $successful) {
            return SmsResult::failed('provider: '.json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        return SmsResult::sent($messageId === null ? null : (string) $messageId);
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim((string) ($this->config['base_url'] ?? ''), '/').'/')
            ->timeout((int) ($this->config['timeout_seconds'] ?? 15))
            ->acceptJson();
    }

    private function endpoint(string $name): string
    {
        return (string) ($this->config['endpoints'][$name] ?? $name);
    }
}
