<?php

namespace App\Ai\Providers;

use App\Contracts\AiProvider;

/**
 * What runs when no model is configured. It answers "nothing", the caller
 * falls back to the manual form, and the product works exactly as it did
 * before the AI layer existed — which is the property that makes the feature
 * a shortcut rather than a gate.
 */
class NullAiProvider implements AiProvider
{
    public function structured(string $systemPrompt, string $userInput, array $schema): ?array
    {
        return null;
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
