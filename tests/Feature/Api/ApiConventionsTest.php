<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ApiConventionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_and_error_envelopes(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonStructure(['success', 'message', 'data']);

        $this->getJson('/api/v1/does-not-exist')->assertStatus(404)->assertExactJsonStructure(['success', 'message', 'errors', 'code'])
            ->assertJsonPath('code', 'NOT_FOUND');
        $this->deleteJson('/api/v1/me/tokens/abc')->assertStatus(404);
        $this->putJson('/api/v1/me')->assertStatus(405)->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
        $this->postJson('/api/v1/links', [])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonStructure(['errors' => ['url']]);
    }

    public function test_locale_aware_messages(): void
    {
        $this->getJson('/api/v1/me', ['Accept-Language' => 'ar'])->assertStatus(401)->assertJsonPath('message', 'يلزم تسجيل الدخول.')->assertHeader('Content-Language', 'ar');
        $this->getJson('/api/v1/me', ['Accept-Language' => 'en'])->assertJsonPath('message', 'Authentication is required.');

        $user = User::factory()->create(['locale' => 'ar']);
        $this->actingAsUser($user);
        $this->getJson('/api/v1/links/'.Str::uuid())->assertJsonPath('message', 'المورد المطلوب غير موجود.');
    }

    public function test_no_stack_traces_in_production_mode(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/v1/__boom', fn () => throw new \RuntimeException('internal secret detail'));

        $response = $this->getJson('/api/v1/__boom')->assertStatus(500)->assertJsonPath('code', 'SERVER_ERROR');
        $this->assertStringNotContainsString('internal secret detail', $response->getContent());
        $this->assertArrayNotHasKey('debug', $response->json());
    }

    public function test_security_headers_and_private_caching(): void
    {
        $this->actingAsUser();
        $response = $this->getJson('/api/v1/me');
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->app['auth']->forgetGuards();
        $public = $this->getJson('/api/v1/public/categories');
        $this->assertStringContainsString('public', (string) $public->headers->get('Cache-Control'));
    }

    public function test_cors_allows_only_the_configured_frontend_with_credentials(): void
    {
        $ok = $this->call('OPTIONS', '/api/v1/links', [], [], [], ['HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        $ok->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')->assertHeader('Access-Control-Allow-Credentials', 'true');

        $evil = $this->call('OPTIONS', '/api/v1/links', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        $this->assertNotSame('https://evil.example', $evil->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_every_api_route_is_documented_in_openapi(): void
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));
        $this->assertSame('3.1.0', $spec['openapi']);

        $documented = [];
        foreach ($spec['paths'] as $path => $ops) {
            foreach (array_keys($ops) as $method) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $documented[] = strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', '/api/v1'.$path);
                }
            }
        }

        $missing = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || str_contains($route->uri(), '__boom')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $key = $method.' '.preg_replace('/\{[^}]+\}/', '{}', '/'.$route->uri());
                if (! in_array($key, $documented, true)) {
                    $missing[] = $key;
                }
            }
        }

        $this->assertSame([], $missing, 'Undocumented routes: '.implode(', ', $missing));
    }
}
