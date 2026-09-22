<?php

namespace App\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Payments\PaymentRequest;
use App\Payments\TokenResult;
use App\Payments\VerificationResult;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Saman Bank's gateway (پرداخت الکترونیک سامان).
 *
 * VERIFY BEFORE THE FIRST LIVE TRANSACTION: field names and result codes
 * differ between SEP terminal generations. Confirm them against the
 * integration document issued with your terminal and adjust only the two
 * methods that touch the wire. Nothing above this class depends on them.
 */
class SepGateway implements PaymentGateway
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
    ) {}

    public function requestToken(PaymentRequest $request): ?TokenResult
    {
        if (blank($this->config['terminal_id'] ?? null)) {
            Log::error('SEP terminal id is not configured.');

            return null;
        }

        try {
            $response = $this->http
                ->connectTimeout((int) ($this->config['connect_timeout'] ?? 5))
                ->timeout((int) ($this->config['token_timeout'] ?? 15))
                ->acceptJson()
                ->post($this->config['token_url'], [
                    'Action' => 'token',
                    'TerminalId' => $this->config['terminal_id'],
                    'Amount' => $request->amount,
                    'ResNum' => $request->reference,
                    'RedirectUrl' => $request->callbackUrl,
                    'CellNumber' => $request->payerPhone,
                ]);
        } catch (Throwable $e) {
            // A payer is waiting in front of this. Returning null shows them
            // the invoice again, which beats a redirect into nothing.
            Log::warning('SEP token request failed', ['error' => $e->getMessage()]);

            return null;
        }

        $body = $response->json() ?? [];

        $token = $body['token'] ?? $body['Token'] ?? null;
        $status = $body['status'] ?? $body['Status'] ?? null;

        if ($response->failed() || (int) $status !== 1 || blank($token)) {
            Log::warning('SEP refused a token request', [
                'status' => $status,
                'error_code' => $body['errorCode'] ?? $body['ErrorCode'] ?? null,
                'error' => $body['errorDesc'] ?? $body['ErrorDesc'] ?? null,
            ]);

            return null;
        }

        return new TokenResult(
            token: (string) $token,
            redirectUrl: $this->config['payment_url'].'?Token='.urlencode((string) $token).'&GetMethod=true',
        );
    }

    public function verify(string $reference, int $expectedAmount): VerificationResult
    {
        try {
            $response = $this->http
                ->connectTimeout((int) ($this->config['connect_timeout'] ?? 5))
                ->timeout((int) ($this->config['verify_timeout'] ?? 30))
                ->acceptJson()
                ->post($this->config['verify_url'], [
                    'RefNum' => $reference,
                    'TerminalNumber' => $this->config['terminal_id'],
                ]);
        } catch (Throwable $e) {
            // Unreachable is not "not paid". The payment stays awaiting
            // verification and is retried rather than written off, because
            // the money may well have moved.
            return VerificationResult::refused('unreachable: '.$e->getMessage());
        }

        if ($response->failed()) {
            return VerificationResult::refused("http {$response->status()}");
        }

        $body = $response->json() ?? [];

        if (($body['Success'] ?? $body['success'] ?? false) !== true) {
            return VerificationResult::refused(
                'gateway: '.($body['ResultDescription'] ?? $body['ResultCode'] ?? 'unknown'),
                $body,
            );
        }

        $settled = $body['TransactionDetail']['AffectiveAmount']
            ?? $body['TransactionDetail']['OrginalAmount']
            ?? $body['TransactionDetail']['Amount']
            ?? null;

        if ($settled === null) {
            return VerificationResult::refused('gateway did not report an amount', $body);
        }

        // The check that matters. The callback reaches us through the payer's
        // own browser, so its amount is a claim; this one is the bank's. A
        // mismatch means the request was tampered with and the transaction is
        // refused however cleanly everything else came back.
        if ((int) $settled !== $expectedAmount) {
            Log::critical('SEP amount mismatch — refusing to settle', [
                'reference' => $reference,
                'expected' => $expectedAmount,
                'settled' => $settled,
            ]);

            return VerificationResult::refused('amount mismatch', $body);
        }

        return VerificationResult::settled((int) $settled, $body);
    }
}
