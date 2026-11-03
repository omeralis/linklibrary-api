<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiException;
use App\Services\Ai\AiInput;
use App\Services\Ai\AiQuotaException;
use App\Services\Ai\PromptBuilder;
use App\Services\Ai\Providers\GeminiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiProviderTest extends TestCase
{
    private function provider(bool $ack = true, string $key = 'test-key-not-real'): GeminiProvider
    {
        return new GeminiProvider(
            ['api_key' => $key, 'model' => 'gemini-test', 'endpoint' => 'https://gemini.test/v1beta', 'free_tier_acknowledged' => $ack],
            new PromptBuilder, 20.0, 800, 4000,
        );
    }

    private function input(): AiInput
    {
        return new AiInput('example.com', 'عنوان', 'وصف', null, 'نص المقال', 'ar', 'ARTICLE', ['programming']);
    }

    public function test_it_is_disabled_without_key_or_free_tier_acknowledgement(): void
    {
        $this->assertFalse($this->provider(ack: false)->isConfigured());
        $this->assertFalse($this->provider(key: '')->isConfigured());
        $this->assertTrue($this->provider()->isConfigured());
    }

    public function test_it_sends_the_key_in_a_header_and_parses_json_output(): void
    {
        Http::fake(['gemini.test/*' => Http::response([
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode(['short_summary' => 'ملخص', 'tags' => ['php']])]]]]],
            'usageMetadata' => ['promptTokenCount' => 120, 'candidatesTokenCount' => 40],
        ])]);

        $out = $this->provider()->analyze($this->input());

        $this->assertSame('ملخص', $out['data']['short_summary']);
        $this->assertSame([120, 40], [$out['input_tokens'], $out['output_tokens']]);
        Http::assertSent(function (Request $r) {
            return $r->url() === 'https://gemini.test/v1beta/models/gemini-test:generateContent'
                && $r->hasHeader('x-goog-api-key', 'test-key-not-real')
                && ! str_contains($r->url(), 'key=')
                && $r['generationConfig']['responseMimeType'] === 'application/json';
        });
    }

    public function test_per_minute_429_is_a_short_quota_wait(): void
    {
        Http::fake(['gemini.test/*' => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'details' => [
            ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier']]],
            ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '34s'],
        ]]], 429)]);

        try {
            $this->provider()->analyze($this->input());
            $this->fail('expected quota exception');
        } catch (AiQuotaException $e) {
            $this->assertFalse($e->daily);
            $this->assertSame(34, $e->retryAfterSeconds);
        }
    }

    public function test_daily_429_is_flagged_as_daily(): void
    {
        Http::fake(['gemini.test/*' => Http::response(['error' => ['details' => [
            ['violations' => [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier']]],
        ]]], 429)]);

        try {
            $this->provider()->analyze($this->input());
            $this->fail('expected quota exception');
        } catch (AiQuotaException $e) {
            $this->assertTrue($e->daily);
        }
    }

    public function test_error_classification(): void
    {
        $cases = [[500, 'PROVIDER_HTTP_500', true], [503, 'PROVIDER_HTTP_503', true], [400, 'PROVIDER_HTTP_400', false], [403, 'PROVIDER_AUTH', false]];
        $sequence = Http::sequence();
        foreach ($cases as [$status]) {
            $sequence->push(['error' => ['message' => 'x']], $status);
        }
        Http::fake(['gemini.test/*' => $sequence]);
        foreach ($cases as [, $code, $retryable]) {
            try {
                $this->provider()->analyze($this->input());
                $this->fail("expected {$code}");
            } catch (AiException $e) {
                $this->assertSame($code, $e->errorCode);
                $this->assertSame($retryable, $e->retryable);
            }
        }
    }

    public function test_blocked_truncated_or_malformed_output_is_rejected(): void
    {
        $cases = [
            ['promptFeedback' => ['blockReason' => 'SAFETY']],
            ['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => '{"a":']]]]]],
            ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'not json']]]]]],
            ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '[1,2]']]]]]],
        ];
        $sequence = Http::sequence();
        foreach ($cases as $body) {
            $sequence->push($body);
        }
        Http::fake(['gemini.test/*' => $sequence]);
        foreach ($cases as $_) {
            try {
                $this->provider()->analyze($this->input());
                $this->fail('expected rejection');
            } catch (AiException $e) {
                $this->assertFalse($e->retryable);
            }
        }
    }
}
