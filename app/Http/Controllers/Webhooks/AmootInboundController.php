<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundSms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Receives replies from the dedicated line.
 *
 * The endpoint does as little as possible: authenticate, pull out the three
 * fields that matter, hand the rest to a queued job. A provider that times out
 * waiting for us starts redelivering, and redelivery is how a task gets closed
 * twice.
 */
class AmootInboundController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->isAuthentic($request)) {
            return response()->json(['ok' => false], 403);
        }

        $payload = $request->all();

        $from = $this->pick($payload, ['From', 'from', 'Mobile', 'mobile', 'sender']);
        $body = $this->pick($payload, ['Text', 'text', 'Message', 'message', 'body']);

        if ($from === null || $body === null) {
            return response()->json(['ok' => false, 'error' => 'missing from/body'], 422);
        }

        // Falling back to a generated id keeps a provider that sends none from
        // being rejected outright, though it does forfeit replay protection
        // for that message — the unique index is what normally provides it.
        $messageId = $this->pick($payload, ['MessageID', 'MessageId', 'message_id', 'id'])
            ?? 'gen-'.Str::uuid()->toString();

        ProcessInboundSms::dispatch($from, $body, $messageId, $payload);

        return response()->json(['ok' => true]);
    }

    /**
     * The route path is unguessable, but a leaked path should not be enough to
     * inject replies into someone's tasks. Compared in constant time so the
     * secret cannot be recovered by timing the responses.
     */
    private function isAuthentic(Request $request): bool
    {
        $expected = config('sms.amoot.inbound_secret');

        if (blank($expected)) {
            return false;
        }

        $provided = $request->header('X-Webhook-Secret')
            ?? $request->query('secret')
            ?? $request->input('secret');

        return is_string($provided) && hash_equals($expected, $provided);
    }

    /**
     * Field names vary between panel generations, so the first key that
     * carries a value wins rather than one name being hard-coded.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function pick(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (filled($payload[$key] ?? null)) {
                return (string) $payload[$key];
            }
        }

        return null;
    }
}
