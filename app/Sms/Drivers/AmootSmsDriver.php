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
 * Built to Amoot's published REST reference (github.com/AmootSoft/AmootSMS):
 * SendWithPatternOWN with Token, LineNumber, Mobile, PatternCodeID and
 * PatternValues, and AccountStatus for the balance. The wire format stays in
 * this class, so if a panel ever differs, nothing outside it has to change.
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
                $this->sendEndpoint(),
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

    /**
     * AccountStatus reports the balance as RemaindCredit (Amoot's spelling),
     * either at the top level or under Data.
     */
    public function credit(): ?int
    {
        try {
            $response = $this->request()->get(
                $this->configured('credit') ?? 'AccountStatus',
                array_filter(['Token' => $this->config['token'] ?? null]),
            );
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $body = $response->json() ?? [];
        $data = is_array($body['Data'] ?? null) ? $body['Data'] : [];

        $value = $body['RemaindCredit'] ?? $data['RemaindCredit'] ?? $body['Credit'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Values travel as one comma-separated list in the pattern's variable
     * order, so a Latin comma inside a value (a task titled "میز, صندلی")
     * would shift every value after it. It is folded to the Persian comma,
     * which reads the same to the recipient.
     *
     * The token goes both in the Authorization header, as Amoot's C#
     * reference sends it, and as a Token field, as its PHP reference does.
     *
     * @return array<string, scalar>
     */
    protected function payloadFor(PatternMessage $message): array
    {
        $values = array_map(
            static fn (string $value): string => str_replace(',', '،', $value),
            $message->orderedValues(),
        );

        return array_filter([
            'Token' => $this->config['token'] ?? null,
            'LineNumber' => $this->lineNumber(),
            'Mobile' => $message->phone,
            'PatternCodeID' => $message->code,
            'PatternValues' => implode(',', $values),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Amoot answers with a top-level Status and the per-recipient rows under
     * Data. Anything not recognisably a success is recorded as a failure, with
     * the whole response kept, so a silently dropped message is never logged
     * as delivered.
     *
     * @param  array<string, mixed>  $body
     */
    protected function interpret(array $body): SmsResult
    {
        $status = $body['Status'] ?? $body['status'] ?? null;
        $first = is_array($body['Data'] ?? null) ? ($body['Data'][0] ?? $body['Data']) : [];
        $messageId = $first['MessageID'] ?? $body['MessageID'] ?? $body['MessageId'] ?? null;

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

    /**
     * A dedicated line sends through SendWithPatternOWN with its LineNumber;
     * without one, the pattern goes out on Amoot's shared line.
     */
    private function sendEndpoint(): string
    {
        return $this->configured('send_pattern')
            ?? ($this->lineNumber() !== null ? 'SendWithPatternOWN' : 'SendWithPattern');
    }

    private function lineNumber(): ?string
    {
        $line = trim((string) ($this->config['line_number'] ?? ''));

        return $line === '' ? null : $line;
    }

    private function configured(string $endpoint): ?string
    {
        $value = trim((string) ($this->config['endpoints'][$endpoint] ?? ''));

        return $value === '' ? null : $value;
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim((string) ($this->config['base_url'] ?? ''), '/').'/')
            ->timeout((int) ($this->config['timeout_seconds'] ?? 15))
            ->withHeaders(array_filter(['Authorization' => $this->config['token'] ?? null]))
            ->acceptJson();
    }
}
