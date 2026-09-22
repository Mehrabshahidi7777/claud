<?php

namespace App\Ai\Providers;

use App\Contracts\AiProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A model running on the customer's own server.
 *
 * This is the one AI arrangement that can be sold to a bank, an insurer or a
 * state contractor, because the sentence "your data never leaves your server"
 * is literally true. It is also slow, which is why every call goes through a
 * queue and never blocks a request.
 */
class OllamaProvider implements AiProvider
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
    ) {}

    public function structured(string $systemPrompt, string $userInput, array $schema): ?array
    {
        try {
            $response = $this->http
                ->timeout((int) ($this->config['timeout_seconds'] ?? 120))
                ->acceptJson()
                ->post(rtrim($this->config['base_url'], '/').'/api/chat', [
                    'model' => $this->config['model'],
                    'stream' => false,

                    // Ollama constrains generation to the schema, which is what
                    // makes the output parseable often enough to be useful.
                    // It is still validated afterwards: constrained is not
                    // the same as correct.
                    'format' => $schema,

                    'options' => [
                        'temperature' => (float) ($this->config['temperature'] ?? 0.1),
                    ],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userInput],
                    ],
                ]);
        } catch (Throwable $e) {
            Log::warning('Ollama unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Ollama returned an error', ['status' => $response->status()]);

            return null;
        }

        $content = $response->json('message.content');

        if (! is_string($content)) {
            return null;
        }

        $decoded = json_decode($content, associative: true);

        return is_array($decoded) ? $decoded : null;
    }

    public function isAvailable(): bool
    {
        try {
            return $this->http
                ->timeout(3)
                ->get(rtrim($this->config['base_url'], '/').'/api/tags')
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
