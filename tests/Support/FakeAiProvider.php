<?php

namespace Tests\Support;

use App\Services\Ai\AiException;
use App\Services\Ai\AiInput;
use App\Services\Ai\AiProvider;

class FakeAiProvider implements AiProvider
{
    /** @var list<AiInput> */
    public array $inputs = [];

    /** @param array<string, mixed>|AiException|null $response */
    public function __construct(public array|AiException|null $response = null) {}

    public function name(): string
    {
        return 'fake';
    }

    public function model(): ?string
    {
        return 'fake-model';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function analyze(AiInput $input): array
    {
        $this->inputs[] = $input;
        if ($this->response instanceof AiException) {
            throw $this->response;
        }

        return ['data' => $this->response ?? self::valid(), 'input_tokens' => 100, 'output_tokens' => 50];
    }

    /** @return array<string, mixed> */
    public static function valid(array $overrides = []): array
    {
        return array_merge([
            'suggested_title' => 'AI Title',
            'short_summary' => 'A short AI summary.',
            'detailed_summary' => 'A longer AI generated summary of the resource.',
            'category' => 'programming',
            'tags' => ['laravel', 'php'],
            'language' => 'en',
            'content_type' => 'ARTICLE',
            'suspicious' => false,
            'suspicious_reasons' => [],
        ], $overrides);
    }
}
