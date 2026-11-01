<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', array_merge([
            'name' => 'Sara Ali',
            'username' => 'Sara_Ali',
            'email' => 'Sara@Example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'device_name' => 'tests',
        ], $overrides));
    }

    public function test_registration_normalizes_identity_and_sends_verification(): void
    {
        Notification::fake();

        $response = $this->register();

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.username', 'sara_ali')
            ->assertJsonPath('data.user.email', 'sara@example.com')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.auth_mode', 'token');
        $this->assertNotEmpty($response->json('data.token'));

        $user = User::query()->where('email', 'sara@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('secret-pass-123', $user->password));
        $this->assertNotSame('secret-pass-123', $user->password);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.registered', 'actor_user_id' => $user->id]);
    }

    public function test_registration_validation(): void
    {
        User::factory()->create(['email' => 'taken@example.com', 'username' => 'taken']);

        $this->register(['email' => 'TAKEN@example.com', 'username' => 'taken', 'password_confirmation' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['email', 'username', 'password']);

        $this->register(['username' => 'admin'])->assertJsonValidationErrors(['username']);
        $this->register(['username' => 'bad name!'])->assertJsonValidationErrors(['username']);
    }

    public function test_login_with_token_and_logout(): void
    {
        $user = User::factory()->create(['email' => 'u@example.com', 'password' => 'secret-pass-123']);

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'U@example.com', 'password' => 'secret-pass-123', 'device_name' => 'cli']);
        $login->assertOk()->assertJsonPath('data.auth_mode', 'token');
        $token = $login->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.public_id', $user->public_id);
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_spa_cookie_login_from_stateful_origin(): void
    {
        User::factory()->create(['email' => 'spa@example.com', 'password' => 'secret-pass-123']);

        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/login'])
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/api/v1/auth/login', ['email' => 'spa@example.com', 'password' => 'secret-pass-123'])
            ->assertOk()
            ->assertJsonPath('data.auth_mode', 'session')
            ->assertJsonMissingPath('data.token');
    }

    public function test_invalid_credentials_do_not_reveal_account_existence(): void
    {
        User::factory()->create(['email' => 'exists@example.com', 'password' => 'secret-pass-123']);

        $a = $this->postJson('/api/v1/auth/login', ['email' => 'exists@example.com', 'password' => 'wrong-password']);
        $b = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']);

        $a->assertStatus(422);
        $b->assertStatus(422);
        $this->assertSame($a->json('errors'), $b->json('errors'));
        $this->assertSame(2, AuditLog::query()->where('action', 'user.login_failed')->count());
    }

    public function test_suspended_accounts_cannot_log_in_or_use_the_api(): void
    {
        $user = User::factory()->suspended()->create(['email' => 's@example.com', 'password' => 'secret-pass-123']);
        $this->postJson('/api/v1/auth/login', ['email' => 's@example.com', 'password' => 'secret-pass-123', 'device_name' => 'x'])
            ->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_SUSPENDED');

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/links')->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'rl@example.com', 'password' => 'bad-password-1']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'rl@example.com', 'password' => 'bad-password-1'])
            ->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
    }

    public function test_email_verification_with_signed_link(): void
    {
        $user = User::factory()->unverified()->create();
        $params = VerifyEmailNotification::signedParameters($user);

        $this->getJson("/api/v1/auth/email/verify/{$params['id']}/{$params['hash']}?expires={$params['expires']}&signature={$params['signature']}")
            ->assertOk()->assertJsonPath('success', true);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_email_verification_rejects_tampered_links(): void
    {
        $user = User::factory()->unverified()->create();
        $params = VerifyEmailNotification::signedParameters($user);

        $this->getJson("/api/v1/auth/email/verify/{$params['id']}/".sha1('other@example.com')."?expires={$params['expires']}&signature={$params['signature']}")
            ->assertStatus(403);
        $this->getJson("/api/v1/auth/email/verify/{$params['id']}/{$params['hash']}?expires={$params['expires']}&signature=deadbeef")
            ->assertStatus(403);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_verification(): void
    {
        Notification::fake();
        $user = $this->actingAsUser(User::factory()->unverified()->create());
        $this->postJson('/api/v1/auth/email/verification-notification')->assertStatus(202);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_forgot_password_response_is_identical_for_unknown_addresses(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'known@example.com']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'known@example.com']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.com']);

        $known->assertStatus(202);
        $unknown->assertStatus(202);
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_reset_password_flow(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $user->createToken('old-device');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com']);

        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($n) use (&$token, $user) {
            $url = $n->toMail($user)->actionUrl;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $token = $q['token'];

            return str_starts_with($url, 'http://localhost:3000/reset-password');
        });

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'reset@example.com', 'token' => 'wrong', 'password' => 'new-secret-456', 'password_confirmation' => 'new-secret-456'])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_RESET_TOKEN');

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'reset@example.com', 'token' => $token, 'password' => 'new-secret-456', 'password_confirmation' => 'new-secret-456'])
            ->assertOk();

        $this->assertTrue(Hash::check('new-secret-456', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count(), 'existing tokens are revoked after reset');
        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    public function test_protected_endpoints_require_authentication(): void
    {
        foreach (['/api/v1/me', '/api/v1/links', '/api/v1/collections', '/api/v1/shared-with-me', '/api/v1/notifications', '/api/v1/search?q=abc'] as $uri) {
            $this->getJson($uri)->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
        }
    }
}
