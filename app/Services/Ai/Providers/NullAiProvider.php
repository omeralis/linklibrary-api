<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiException;
use App\Services\Ai\AiInput;
use App\Services\Ai\AiProvider;

/** Used when no AI provider is configured: the application works without AI. */
class NullAiProvider implements AiProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function model(): ?string
    {
        return null;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function analyze(AiInput $input): array
    {
        throw new AiException('AI_DISABLED');
    }
}
