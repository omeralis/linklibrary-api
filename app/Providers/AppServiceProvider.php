<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Ai\AiOutputValidator;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiService;
use App\Services\Ai\PromptBuilder;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\NullAiProvider;
use App\Services\Ai\Providers\OpenAiCompatibleProvider;
use App\Services\Extraction\ExtractionService;
use App\Services\Extraction\Extractors\GenericExtractor;
use App\Services\Extraction\Extractors\GitHubExtractor;
use App\Services\Extraction\Extractors\VimeoExtractor;
use App\Services\Extraction\Extractors\YouTubeExtractor;
use App\Services\Extraction\HtmlMetadataParser;
use App\Services\Extraction\PdfMetadataParser;
use App\Services\Extraction\RobotsPolicy;
use App\Services\Http\CurlTransport;
use App\Services\Http\DnsResolver;
use App\Services\Http\HttpTransport;
use App\Services\Http\IpAddressPolicy;
use App\Services\Http\SafeHttpClient;
use App\Services\Http\SystemDnsResolver;
use App\Services\Links\TagService;
use App\Services\Search\SearchIndexer;
use App\Services\Security\GoogleSafeBrowsingProvider;
use App\Services\Security\GoogleWebRiskProvider;
use App\Services\Security\HeuristicReputationProvider;
use App\Services\Security\ReputationService;
use App\Services\Security\SecurityPolicy;
use App\Services\Security\SecurityScanService;
use App\Services\Url\UrlNormalizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UrlNormalizer::class, fn () => new UrlNormalizer(
            (int) config('linklibrary.urls.max_length'),
            (bool) config('linklibrary.urls.keep_route_fragments'),
        ));
        $this->app->singleton(IpAddressPolicy::class);
        $this->app->singleton(DnsResolver::class, SystemDnsResolver::class);
        $this->app->singleton(HttpTransport::class, fn () => new CurlTransport(config('linklibrary.fetch.proxy') ?: null));

        $this->app->singleton(SafeHttpClient::class, fn ($app) => new SafeHttpClient(
            normalizer: $app->make(UrlNormalizer::class),
            resolver: $app->make(DnsResolver::class),
            ipPolicy: $app->make(IpAddressPolicy::class),
            transport: $app->make(HttpTransport::class),
            userAgent: (string) config('linklibrary.fetch.user_agent'),
            connectTimeout: (float) config('linklibrary.fetch.connect_timeout'),
            timeout: (float) config('linklibrary.fetch.timeout'),
            maxRedirects: (int) config('linklibrary.fetch.max_redirects'),
            defaultMaxBytes: (int) config('linklibrary.fetch.max_bytes'),
            allowedPorts: (array) config('linklibrary.fetch.allowed_ports'),
            blockedHosts: (array) config('linklibrary.fetch.blocked_hosts'),
            allowDowngrade: (bool) config('linklibrary.fetch.allow_https_to_http_redirect'),
        ));

        $this->app->singleton(SecurityPolicy::class, fn () => new SecurityPolicy((bool) config('linklibrary.security.fetch_when_unknown')));

        $this->app->singleton(ReputationService::class, function () {
            $providers = [];
            foreach ((array) config('linklibrary.security.providers') as $name) {
                $providers[] = match (trim((string) $name)) {
                    'safe_browsing' => new GoogleSafeBrowsingProvider((array) config('linklibrary.security.safe_browsing')),
                    'web_risk' => new GoogleWebRiskProvider((array) config('linklibrary.security.web_risk')),
                    'heuristic' => new HeuristicReputationProvider((array) config('linklibrary.security.blocklist_hosts')),
                    default => null,
                };
            }

            return new ReputationService(array_values(array_filter($providers)));
        });

        $this->app->singleton(RobotsPolicy::class, fn ($app) => new RobotsPolicy(
            $app->make(SafeHttpClient::class),
            (string) config('linklibrary.fetch.robots_user_agent'),
            (int) config('linklibrary.fetch.robots_cache_ttl'),
        ));
        $this->app->singleton(HtmlMetadataParser::class, fn ($app) => new HtmlMetadataParser(
            $app->make(UrlNormalizer::class),
            (int) config('linklibrary.extraction.text_excerpt_chars'),
        ));

        $this->app->bind(ExtractionService::class, fn ($app) => new ExtractionService(
            extractors: [
                new YouTubeExtractor($app->make(SafeHttpClient::class), config('linklibrary.extraction.youtube_api_key')),
                new VimeoExtractor($app->make(SafeHttpClient::class)),
                new GitHubExtractor($app->make(SafeHttpClient::class), config('linklibrary.extraction.github_token')),
                new GenericExtractor(
                    $app->make(SafeHttpClient::class),
                    $app->make(RobotsPolicy::class),
                    $app->make(HtmlMetadataParser::class),
                    new PdfMetadataParser,
                    (bool) config('linklibrary.fetch.respect_robots'),
                    (int) config('linklibrary.fetch.max_pdf_bytes'),
                ),
            ],
            normalizer: $app->make(UrlNormalizer::class),
            policy: $app->make(SecurityPolicy::class),
        ));

        $this->app->singleton(AiProvider::class, function ($app) {
            $ai = (array) config('linklibrary.ai');
            $args = [$app->make(PromptBuilder::class), (float) $ai['timeout'], (int) $ai['max_output_tokens'], (int) $ai['max_input_chars']];

            return match ($ai['provider'] ?? 'null') {
                'anthropic' => new AnthropicProvider((array) $ai['anthropic'], ...$args),
                'openai' => new OpenAiCompatibleProvider((array) $ai['openai'], ...$args),
                default => new NullAiProvider,
            };
        });

        $this->app->bind(AiService::class, fn ($app) => new AiService(
            $app->make(AiProvider::class),
            $app->make(AiOutputValidator::class),
            $app->make(TagService::class),
            $app->make(SearchIndexer::class),
            $app->make(SecurityScanService::class),
            (int) config('linklibrary.ai.daily_request_limit'),
            (int) config('linklibrary.ai.max_input_chars'),
        ));
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(10)->letters()->numbers());

        Gate::define('admin', fn (User $user) => $user->isAdmin() && $user->isActive());

        $this->configureRateLimiting();
    }

    /**
     * Client IP for anonymous rate limiting. Requests from the trusted Next.js server (valid
     * X-Frontend-Key) are attributed to the end user's IP from X-Client-IP, so server-side rendering
     * does not exhaust one shared per-IP budget. Anything else uses the connection IP.
     */
    public static function clientIp(Request $request): string
    {
        $secret = (string) config('linklibrary.frontend.server_key');
        $presented = (string) $request->header('X-Frontend-Key', '');
        if ($secret !== '' && $presented !== '' && hash_equals($secret, $presented)) {
            $forwarded = (string) $request->header('X-Client-IP', '');

            return filter_var($forwarded, FILTER_VALIDATE_IP) !== false ? 'fwd:'.$forwarded : 'frontend-server';
        }

        return (string) $request->ip();
    }

    private function configureRateLimiting(): void
    {
        $key = fn (Request $r) => $r->user()?->id ? 'u:'.$r->user()->id : 'ip:'.self::clientIp($r);

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($key($r)));
        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(60)->by('ip:'.self::clientIp($r)));
        RateLimiter::for('search', fn (Request $r) => Limit::perMinute(30)->by($key($r)));

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by('login:'.mb_strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(20)->by('login-ip:'.$r->ip()),
        ]);
        RateLimiter::for('register', fn (Request $r) => Limit::perHour(10)->by('register:'.$r->ip()));
        RateLimiter::for('password-reset', fn (Request $r) => [
            Limit::perMinute(5)->by('pwreset-ip:'.$r->ip()),
            Limit::perHour(5)->by('pwreset:'.mb_strtolower((string) $r->input('email'))),
        ]);
        RateLimiter::for('verification', fn (Request $r) => Limit::perMinute(6)->by($key($r)));

        RateLimiter::for('link-create', fn (Request $r) => [Limit::perMinute(30)->by($key($r)), Limit::perDay(1000)->by('day:'.$key($r))]);
        RateLimiter::for('invitations', fn (Request $r) => Limit::perHour(30)->by($key($r)));
        RateLimiter::for('invitation-accept', fn (Request $r) => Limit::perMinute(10)->by($key($r)));
        RateLimiter::for('rescan', fn (Request $r) => Limit::perHour(10)->by($key($r)));
        RateLimiter::for('reanalyze', fn (Request $r) => Limit::perHour(20)->by($key($r)));
        RateLimiter::for('appeals', fn (Request $r) => Limit::perDay(10)->by($key($r)));
    }
}
