<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiException;
use App\Services\Ai\AiInput;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiQuotaException;
use App\Services\Ai\PromptBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini API (generateContent), intended for the FREE tier only.
 *
 *  - The API key travels in the `x-goog-api-key` header, never in the URL, and is never logged.
 *  - Only the public AiInput is sent (see PromptBuilder); no user data.
 *  - 429 → AiQuotaException (wait for reset); there is no fallback provider and no paid path.
 *  - Disabled unless the owner explicitly acknowledged the free-tier data terms
 *    (AI_GEMINI_FREE_TIER_ACKNOWLEDGED=true), because free-tier content may be used by Google to improve products.
 */
class GeminiProvider implements AiProvider
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
        return 'gemini';
    }

    public function model(): ?string
    {
        return (string) $this->config['model'];
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key']) && ! empty($this->config['free_tier_acknowledged']);
    }

    public function analyze(AiInput $input): array
    {
        $url = rtrim((string) $this->config['endpoint'], '/').'/models/'.rawurlencode((string) $this->model()).':generateContent';

        try {
            $response = Http::timeout((int) ceil($this->timeout))
                ->connectTimeout(10)
                ->withHeaders(['x-goog-api-key' => (string) $this->config['api_key']])
                ->acceptJson()
                ->post($url, [
                    'systemInstruction' => ['parts' => [['text' => $this->prompts->system()]]],
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [['text' => $this->prompts->user($input, $this->maxInputChars)."\n\nReturn ONLY a JSON object that conforms to this JSON schema:\n".json_encode($this->prompts->schema(), JSON_UNESCAPED_UNICODE)]],
                    ]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'maxOutputTokens' => $this->maxOutputTokens,
                        'temperature' => 0.2,
                    ],
                ]);
        } catch (ConnectionException) {
            throw new AiException('PROVIDER_UNREACHABLE', true);
        }

        if ($response->status() === 429) {
            throw $this->quotaException($response);
        }
        if ($response->serverError()) {
            throw new AiException('PROVIDER_HTTP_'.$response->status(), true);
        }
        if (in_array($response->status(), [401, 403], true)) {
            // Invalid / revoked key or API not enabled: retrying cannot help.
            throw new AiException('PROVIDER_AUTH');
        }
        if (! $response->successful()) {
            throw new AiException('PROVIDER_HTTP_'.$response->status());
        }

        if ($response->json('promptFeedback.blockReason') !== null) {
            throw new AiException('PROVIDER_BLOCKED');
        }
        if ($response->json('candidates.0.finishReason') === 'MAX_TOKENS') {
            throw new AiException('INVALID_OUTPUT');
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        $data = is_string($text) ? json_decode($text, true) : null;
        if (! is_array($data) || array_is_list($data)) {
            throw new AiException('INVALID_OUTPUT');
        }

        $in = $response->json('usageMetadata.promptTokenCount');
        $out = $response->json('usageMetadata.candidatesTokenCount');

        return [
            'data' => $data,
            'input_tokens' => is_int($in) ? $in : null,
            'output_tokens' => is_int($out) ? $out : null,
        ];
    }

    /** Distinguish a per-minute rate limit from an exhausted daily quota, and read the suggested delay. */
    private function quotaException(Response $response): AiQuotaException
    {
        $daily = false;
        $retryAfter = null;

        foreach ((array) $response->json('error.details', []) as $detail) {
            if (! is_array($detail)) {
                continue;
            }
            foreach ((array) ($detail['violations'] ?? []) as $violation) {
                $id = is_array($violation) ? (string) ($violation['quotaId'] ?? '').(string) ($violation['quotaMetric'] ?? '') : '';
                if (stripos($id, 'PerDay') !== false) {
                    $daily = true;
                }
            }
            if (isset($detail['retryDelay']) && is_string($detail['retryDelay']) && preg_match('/^(\d+(?:\.\d+)?)s$/', $detail['retryDelay'], $m) === 1) {
                $retryAfter = (int) ceil((float) $m[1]);
            }
        }

        if ($retryAfter === null && is_numeric($response->header('Retry-After'))) {
            $retryAfter = (int) $response->header('Retry-After');
        }

        return new AiQuotaException($retryAfter, $daily);
    }
}
