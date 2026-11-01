<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiException;
use App\Services\Ai\AiInput;
use App\Services\Ai\AiProvider;
use App\Services\Ai\PromptBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** OpenAI-compatible Chat Completions with JSON-schema structured output. */
class OpenAiCompatibleProvider implements AiProvider
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
        return 'openai';
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
        $schema = $this->prompts->schema();

        try {
            $response = Http::timeout((int) ceil($this->timeout))
                ->withToken((string) $this->config['api_key'])
                ->acceptJson()
                ->post((string) $this->config['endpoint'], [
                    'model' => $this->model(),
                    'max_tokens' => $this->maxOutputTokens,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->prompts->system()],
                        ['role' => 'user', 'content' => $this->prompts->user($input, $this->maxInputChars)],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => ['name' => PromptBuilder::TOOL_NAME, 'strict' => false, 'schema' => $schema],
                    ],
                ]);
        } catch (ConnectionException) {
            throw new AiException('PROVIDER_UNREACHABLE', true);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new AiException('PROVIDER_HTTP_'.$response->status(), true);
        }
        if (! $response->successful()) {
            throw new AiException('PROVIDER_HTTP_'.$response->status());
        }

        $content = $response->json('choices.0.message.content');
        $data = is_string($content) ? json_decode($content, true) : null;
        if (! is_array($data)) {
            throw new AiException('INVALID_OUTPUT');
        }

        return [
            'data' => $data,
            'input_tokens' => is_int($response->json('usage.prompt_tokens')) ? $response->json('usage.prompt_tokens') : null,
            'output_tokens' => is_int($response->json('usage.completion_tokens')) ? $response->json('usage.completion_tokens') : null,
        ];
    }
}
