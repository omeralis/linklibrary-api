<?php

namespace App\Services\Ai;

interface AiProvider
{
    public function name(): string;

    public function model(): ?string;

    public function isConfigured(): bool;

    /**
     * Returns the provider's raw structured output (decoded JSON object), not yet validated.
     *
     * @return array{data: array<string, mixed>, input_tokens: ?int, output_tokens: ?int}
     *
     * @throws AiException
     */
    public function analyze(AiInput $input): array;
}
