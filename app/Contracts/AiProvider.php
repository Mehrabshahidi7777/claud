<?php

namespace App\Contracts;

/**
 * The seam that keeps us from being locked to one model. There are two
 * implementations today — a local Ollama and a null object — and a hosted
 * OpenAI-compatible one can be added without anything above this changing.
 */
interface AiProvider
{
    /**
     * Ask the model for structured JSON matching the given schema. Returns the
     * decoded array, or null when the model is unreachable or answers with
     * something that will not parse — in which case the caller falls back to
     * the manual form rather than failing.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    public function structured(string $systemPrompt, string $userInput, array $schema): ?array;

    public function isAvailable(): bool;
}
