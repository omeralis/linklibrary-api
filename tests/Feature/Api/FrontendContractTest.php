<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\LinkInvitationNotification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\OpenApiValidator;
use Tests\TestCase;

/**
 * Walks the API in the order a frontend integrates it and validates every response body against
 * docs/openapi.yaml (including undocumented keys). Also checks that documented request
 * parameters match the validation rules actually enforced.
 */
class FrontendContractTest extends TestCase
{
    use RefreshDatabase;

    private OpenApiValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCategories();
        $this->validator = new OpenApiValidator(base_path('docs/openapi.yaml'));
        Notification::fake();
        $this->transport->html('https://docs.example.com/guide', '<html lang="en"><head><title>Guide</title><meta name="description" content="A guide"></head><body>Hello</body></html>');
    }

    private function contract(TestResponse $response, string $method, string $path): TestResponse
    {
        $problems = $this->validator->validateResponse($method, $path, $response->status(), $response->json());
        $this->assertSame([], $problems, "{$method} {$path} ({$response->status()}) does not match OpenAPI:\n".implode("\n", $problems));

        return $response;
    }

    private function as(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_frontend_flow_matches_the_openapi_contract(): void
    {
        // 1. Auth
        $reg = $this->contract($this->postJson('/api/v1/auth/register', [
            'name' => 'Owner', 'username' => 'owner_one', 'email' => 'owner@example.com',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123', 'device_name' => 'contract',
        ])->assertCreated(), 'POST', '/auth/register');
        $token = $reg->json('data.token');
        $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();

        $this->contract($this->postJson('/api/v1/auth/register', ['email' => 'bad'])->assertStatus(422), 'POST', '/auth/register');
        $this->contract($this->getJson('/api/v1/me')->assertStatus(401), 'GET', '/me');

        $p = VerifyEmailNotification::signedParameters($owner);
        $this->contract($this->getJson("/api/v1/auth/email/verify/{$p['id']}/{$p['hash']}?expires={$p['expires']}&signature={$p['signature']}")->assertOk(), 'GET', '/auth/email/verify/{id}/{hash}');
        $this->contract($this->as($token)->getJson('/api/v1/auth/me')->assertOk(), 'GET', '/auth/me');
        $this->contract($this->as($token)->patchJson('/api/v1/me', ['profile_public' => true, 'locale' => 'ar'])->assertOk(), 'PATCH', '/me');

        // 2. Links
        $created = $this->contract($this->as($token)->postJson('/api/v1/links', ['url' => 'https://docs.example.com/guide', 'tags' => ['docs'], 'notes' => 'n'])->assertCreated(), 'POST', '/links');
        $id = $created->json('data.public_id');
        $this->contract($this->as($token)->postJson('/api/v1/links', ['url' => 'https://docs.example.com/guide'])->assertOk(), 'POST', '/links');
        $this->contract($this->as($token)->postJson('/api/v1/links', ['url' => 'javascript:alert(1)'])->assertStatus(422), 'POST', '/links');
        $this->contract($this->as($token)->getJson('/api/v1/links?per_page=5')->assertOk(), 'GET', '/links');
        $this->contract($this->as($token)->getJson("/api/v1/links/{$id}")->assertOk(), 'GET', '/links/{publicId}');
        $this->contract($this->as($token)->patchJson("/api/v1/links/{$id}", ['custom_title' => 'Mine', 'category' => 'programming'])->assertOk(), 'PATCH', '/links/{publicId}');
        $this->contract($this->as($token)->getJson("/api/v1/links/{$id}/processing-status")->assertOk(), 'GET', '/links/{publicId}/processing-status');
        $this->contract($this->as($token)->getJson("/api/v1/links/{$id}/security")->assertOk(), 'GET', '/links/{publicId}/security');
        $this->contract($this->as($token)->postJson("/api/v1/links/{$id}/favorite")->assertOk(), 'POST', '/links/{publicId}/favorite');
        $this->contract($this->as($token)->getJson("/api/v1/links/{$id}/open")->assertOk(), 'GET', '/links/{publicId}/open');
        $this->contract($this->as($token)->patchJson("/api/v1/links/{$id}/visibility", ['visibility' => 'PUBLIC'])->assertOk(), 'PATCH', '/links/{publicId}/visibility');
        $this->contract($this->as($token)->patchJson("/api/v1/links/{$id}/sharing-settings", ['allow_resharing' => true])->assertOk(), 'PATCH', '/links/{publicId}/sharing-settings');
        $this->contract($this->as($token)->getJson('/api/v1/links/'.Str::uuid())->assertNotFound(), 'GET', '/links/{publicId}');

        // 3. Collections
        $col = $this->contract($this->as($token)->postJson('/api/v1/collections', ['name' => 'Reading', 'visibility' => 'PUBLIC'])->assertCreated(), 'POST', '/collections');
        $cid = $col->json('data.public_id');
        $this->contract($this->as($token)->postJson("/api/v1/collections/{$cid}/links", ['links' => [$id]])->assertOk(), 'POST', '/collections/{publicId}/links');
        $this->contract($this->as($token)->getJson("/api/v1/collections/{$cid}")->assertOk(), 'GET', '/collections/{publicId}');
        $this->contract($this->as($token)->getJson('/api/v1/collections')->assertOk(), 'GET', '/collections');

        // 4. Sharing
        $viewer = User::factory()->create(['email' => 'viewer@example.com']);
        $viewerToken = $viewer->createToken('t')->plainTextToken;
        $this->contract($this->as($token)->postJson("/api/v1/links/{$id}/invitations", ['email' => 'viewer@example.com'])->assertCreated(), 'POST', '/links/{publicId}/invitations');
        $this->contract($this->as($token)->getJson("/api/v1/links/{$id}/invitations")->assertOk(), 'GET', '/links/{publicId}/invitations');
        $inviteToken = null;
        Notification::assertSentTo($viewer, LinkInvitationNotification::class, function ($n) use (&$inviteToken) {
            $inviteToken = $n->tokenForTesting();

            return true;
        });
        $this->contract($this->as($viewerToken)->getJson('/api/v1/me/invitations')->assertOk(), 'GET', '/me/invitations');
        $this->contract($this->as($viewerToken)->postJson("/api/v1/invitations/{$inviteToken}/accept")->assertOk(), 'POST', '/invitations/{token}/accept');
        $this->contract($this->as($viewerToken)->getJson('/api/v1/shared-with-me')->assertOk(), 'GET', '/shared-with-me');
        $this->contract($this->as($viewerToken)->getJson("/api/v1/links/{$id}")->assertOk(), 'GET', '/links/{publicId}');
        $access = $this->as($token)->getJson("/api/v1/links/{$id}/access")->assertOk();
        $this->contract($access, 'GET', '/links/{publicId}/access');

        // 5. Search & taxonomy
        $this->contract($this->as($token)->getJson('/api/v1/search?q=guide&scope=all')->assertOk(), 'GET', '/search');
        $this->contract($this->as($token)->getJson('/api/v1/categories')->assertOk(), 'GET', '/categories');
        $this->contract($this->as($token)->getJson('/api/v1/tags')->assertOk(), 'GET', '/tags');

        // 6. Notifications
        $this->contract($this->as($token)->getJson('/api/v1/notifications/unread-count')->assertOk(), 'GET', '/notifications/unread-count');
        $this->contract($this->as($token)->getJson('/api/v1/notifications')->assertOk(), 'GET', '/notifications');

        // 7. Public explore & profiles (anonymous)
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->contract($this->getJson('/api/v1/public/links')->assertOk()->assertJsonCount(1, 'data'), 'GET', '/public/links');
        $this->contract($this->getJson("/api/v1/public/links/{$id}")->assertOk(), 'GET', '/public/links/{publicId}');
        $this->contract($this->getJson('/api/v1/public/categories')->assertOk(), 'GET', '/public/categories');
        $this->contract($this->getJson('/api/v1/public/tags')->assertOk(), 'GET', '/public/tags');
        $this->contract($this->getJson('/api/v1/public/users/owner_one')->assertOk(), 'GET', '/public/users/{username}');
        $this->contract($this->getJson('/api/v1/public/users/owner_one/links')->assertOk(), 'GET', '/public/users/{username}/links');
        $this->contract($this->getJson('/api/v1/public/users/owner_one/collections')->assertOk(), 'GET', '/public/users/{username}/collections');
        $this->contract($this->getJson("/api/v1/public/collections/{$cid}")->assertOk(), 'GET', '/public/collections/{publicId}');
        $this->contract($this->getJson('/api/v1/public/search?q=guide')->assertOk(), 'GET', '/public/search');
        $this->contract($this->getJson('/api/v1/public/links/'.Str::uuid())->assertNotFound(), 'GET', '/public/links/{publicId}');

        // 8. Revocation (owner) — documented as { revoked_users }.
        $this->app['auth']->forgetGuards();
        $grantId = $access->json('data.0.id');
        $this->contract(
            $this->as($token)->deleteJson("/api/v1/links/{$id}/access/{$grantId}")->assertOk()->assertJsonPath('data.revoked_users', 1),
            'DELETE',
            '/links/{publicId}/access/{grantId}',
        );
    }

    public function test_documented_request_parameters_match_validation_rules(): void
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));
        $problems = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_starts_with($route->uri(), 'api/v1/') || ! str_contains($action, '@') || str_contains($route->uri(), '/admin/')) {
                continue;
            }
            [$class, $method] = explode('@', $action);
            $reflection = new ReflectionMethod($class, $method);
            $keys = [];
            foreach ($reflection->getParameters() as $param) {
                $type = $param->getType()?->getName();
                if ($type !== null && is_subclass_of($type, FormRequest::class)) {
                    $keys = array_keys((new $type)->rules());
                }
            }
            $source = implode('', array_slice(file((string) $reflection->getFileName()), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
            if (preg_match_all('/validate\(\[(.*?)\]\);/s', $source, $m)) {
                foreach ($m[1] as $block) {
                    preg_match_all("/'([a-z_.*]+)' =>/", $block, $k);
                    $keys = [...$keys, ...$k[1]];
                }
            }
            $keys = array_values(array_unique(array_filter(array_map(fn ($k) => preg_replace('/\.\*$/', '', $k), $keys), fn ($k) => ! str_contains((string) $k, '.'))));

            $path = '/'.substr($route->uri(), strlen('api/v1/'));
            foreach (array_diff($route->methods(), ['HEAD']) as $httpMethod) {
                $operation = $spec['paths'][$path][strtolower($httpMethod)] ?? [];
                $documented = [];
                foreach ($operation['parameters'] ?? [] as $p) {
                    if (($p['in'] ?? '') === 'query') {
                        $documented[] = rtrim($p['name'], '[]');
                    }
                }
                $content = $operation['requestBody']['content'] ?? [];
                foreach ($content as $media) {
                    $documented = [...$documented, ...array_keys($media['schema']['properties'] ?? [])];
                }
                $undocumented = array_diff($keys, $documented);
                if ($undocumented !== []) {
                    $problems[] = "{$httpMethod} {$path}: accepted but undocumented: ".implode(', ', $undocumented);
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
