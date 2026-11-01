<?php

namespace Tests;

use App\Enums\SecurityStatus;
use App\Models\Link;
use App\Models\SavedLink;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiService;
use App\Services\Extraction\ExtractionService;
use App\Services\Extraction\RobotsPolicy;
use App\Services\Http\DnsResolver;
use App\Services\Http\HttpTransport;
use App\Services\Http\SafeHttpClient;
use App\Services\Security\HeuristicReputationProvider;
use App\Services\Security\ReputationService;
use App\Services\Security\SecurityScanService;
use App\Services\Security\UrlReputationProvider;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeDnsResolver;
use Tests\Support\FakeReputationProvider;
use Tests\Support\FakeTransport;

abstract class TestCase extends BaseTestCase
{
    protected FakeTransport $transport;

    protected FakeDnsResolver $dns;

    protected FakeReputationProvider $intel;

    /**
     * Safeguard: refuse to run (and therefore to migrate, truncate or roll back) against anything
     * other than a dedicated *_test database. Runs before RefreshDatabase/DatabaseTruncation touch it.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        if (! app()->environment('testing') || ! str_ends_with($database, '_test')) {
            fwrite(STDERR, "\nRefusing to run tests against database \"{$database}\" (APP_ENV=".app()->environment()."). Use a *_test database.\n");
            exit(1);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // No live network access anywhere in the suite.
        Http::preventStrayRequests();
        $this->transport = new FakeTransport;
        $this->dns = new FakeDnsResolver;
        $this->app->instance(HttpTransport::class, $this->transport);
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->forgetNetworkSingletons();

        // Default: an authoritative fake intel provider says CLEAR (individual tests override).
        $this->intel = new FakeReputationProvider(SecurityStatus::CLEAR);
        $this->useReputationProviders([$this->intel, new HeuristicReputationProvider]);
    }

    protected function forgetNetworkSingletons(): void
    {
        foreach ([SafeHttpClient::class, RobotsPolicy::class, ExtractionService::class, AiService::class, SecurityScanService::class] as $class) {
            $this->app->forgetInstance($class);
        }
    }

    /** @param list<UrlReputationProvider> $providers */
    protected function useReputationProviders(array $providers): void
    {
        $this->app->instance(ReputationService::class, new ReputationService($providers));
        $this->forgetNetworkSingletons();
    }

    protected function useAiProvider(AiProvider $provider): void
    {
        $this->app->instance(AiProvider::class, $provider);
        $this->forgetNetworkSingletons();
    }

    protected function seedCategories(): void
    {
        $this->seed(CategorySeeder::class);
    }

    protected function actingAsUser(?User $user = null): User
    {
        $user ??= User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function clearLink(array $attributes = []): Link
    {
        return Link::factory()->security(SecurityStatus::CLEAR)->create($attributes);
    }

    protected function savedLinkFor(User $owner, array $attributes = [], ?Link $link = null): SavedLink
    {
        return SavedLink::factory()->for($owner)->for($link ?? $this->clearLink(), 'link')->create($attributes);
    }

    /** Assert the standard error envelope. */
    protected function assertApiError(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertJsonPath('success', false)->assertJsonPath('code', $code);
    }
}
