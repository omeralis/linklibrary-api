<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiException;
use App\Services\Ai\AiInput;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiQuotaException;
use App\Services\Ai\PromptBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Anthropic Messages API with a forced tool call for structured output. */
class AnthropicProvider implements AiProvider
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly PromptBuilder $prompts,
        private readonly float $timeout,
        private readonly int $maxOutputTokens,
        private readonly int $maxInputChars,
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): ?string
    {
        return (string) $this->config['model'];
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key']);
    }

    public function analyze(AiInput $input): array
    {
        try {
            $response = Http::timeout((int) ceil($this->timeout))
                ->withHeaders([
                    'x-api-key' => (string) $this->config['api_key'],
                    'anthropic-version' => (string) $this->config['version'],
                ])
                ->acceptJson()
                ->post((string) $this->config['endpoint'], [
                    'model' => $this->model(),
                    'max_tokens' => $this->maxOutputTokens,
                    'system' => $this->prompts->system(),
                    'messages' => [['role' => 'user', 'content' => $this->prompts->user($input, $this->maxInputChars)]],
                    'tools' => [[
                        'name' => PromptBuilder::TOOL_NAME,
                        'description' => 'Record the structured analysis of the resource.',
                        'input_schema' => $this->prompts->schema(),
                    ]],
                    'tool_choice' => ['type' => 'tool', 'name' => PromptBuilder::TOOL_NAME],
                ]);
        } catch (ConnectionException) {
            throw new AiException('PROVIDER_UNREACHABLE', true);
        }

        if ($response->status() === 429) {
            $after = $response->header('Retry-After');
            throw new AiQuotaException(is_numeric($after) ? (int) $after : null, is_numeric($after) && (int) $after > 3600);
        }
        if ($response->serverError()) {
            throw new AiException('PROVIDER_HTTP_'.$response->status(), true);
        }
        if (! $response->successful()) {
            throw new AiException('PROVIDER_HTTP_'.$response->status());
        }

        foreach ((array) $response->json('content', []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && is_array($block['input'] ?? null)) {
                return [
                    'data' => $block['input'],
                    'input_tokens' => is_int($response->json('usage.input_tokens')) ? $response->json('usage.input_tokens') : null,
                    'output_tokens' => is_int($response->json('usage.output_tokens')) ? $response->json('usage.output_tokens') : null,
                ];
            }
        }

        throw new AiException('INVALID_OUTPUT');
    }
}
