<?php

namespace Tests\Feature\Auth;

use App\Models\SavedLink;
use App\Models\User;
use App\Notifications\Auth\EmailChangedNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_and_update_profile_preferences(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonStructure(['data' => ['public_id', 'name', 'username', 'email', 'locale', 'theme', 'profile_public']]);

        $this->patchJson('/api/v1/me', ['name' => 'New Name', 'locale' => 'ar', 'theme' => 'dark', 'profile_public' => true, 'bio' => 'مرحبا'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'ar')
            ->assertJsonPath('data.theme', 'dark')
            ->assertJsonPath('data.profile_public', true);

        $this->patchJson('/api/v1/me', ['theme' => 'neon', 'locale' => 'fr'])->assertStatus(422)->assertJsonValidationErrors(['theme', 'locale']);
    }

    public function test_changing_email_requires_password_and_reverification(): void
    {
        Notification::fake();
        $user = $this->actingAsUser(User::factory()->create(['email' => 'old@example.com', 'password' => 'secret-pass-123']));

        $this->patchJson('/api/v1/me', ['email' => 'new@example.com'])->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->patchJson('/api/v1/me', ['email' => 'new@example.com', 'current_password' => 'wrong'])->assertStatus(422);

        $this->patchJson('/api/v1/me', ['email' => 'New@Example.com', 'current_password' => 'secret-pass-123'])
            ->assertOk()->assertJsonPath('data.email', 'new@example.com')->assertJsonPath('data.email_verified', false);

        Notification::assertSentTo($user->fresh(), VerifyEmailNotification::class);
        Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'old@example.com');
    }

    public function test_change_password_requires_current_password(): void
    {
        Notification::fake();
        $user = $this->actingAsUser(User::factory()->create(['password' => 'secret-pass-123']));
        $user->createToken('other-device');

        $this->patchJson('/api/v1/me/password', ['current_password' => 'nope', 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1'])
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);

        $this->patchJson('/api/v1/me/password', ['current_password' => 'secret-pass-123', 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1'])
            ->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass-1', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count(), 'other sessions/tokens are revoked');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_changed', 'actor_user_id' => $user->id]);
    }

    public function test_avatar_upload_is_reencoded_and_svg_rejected(): void
    {
        Storage::fake('public');
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/me/avatar', ['image' => UploadedFile::fake()->image('a.png', 600, 600)])->assertOk();
        $path = $user->fresh()->avatar_path;
        $this->assertStringEndsWith('.webp', (string) $path);
        Storage::disk('public')->assertExists((string) $path);

        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->postJson('/api/v1/me/avatar', ['image' => $svg])->assertStatus(422);
    }

    public function test_account_deletion_requires_password_and_confirmation_and_cascades(): void
    {
        $user = $this->actingAsUser(User::factory()->create(['password' => 'secret-pass-123']));
        $saved = $this->savedLinkFor($user);

        $this->deleteJson('/api/v1/me', ['password' => 'secret-pass-123'])->assertStatus(422);
        $this->deleteJson('/api/v1/me', ['password' => 'wrong', 'confirmation' => 'DELETE'])->assertStatus(422);
        $this->deleteJson('/api/v1/me', ['password' => 'secret-pass-123', 'confirmation' => 'DELETE'])->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(0, SavedLink::withTrashed()->whereKey($saved->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.account_deleted', 'target_id' => $user->public_id]);
    }

    public function test_tokens_can_be_listed_and_revoked(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('laptop')->plainTextToken;
        $other = $user->createToken('phone');

        $this->withToken($token)->getJson('/api/v1/me/tokens')->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->deleteJson('/api/v1/me/tokens/'.$other->accessToken->id)->assertOk();
        $this->assertSame(1, $user->tokens()->count());

        $stranger = User::factory()->create()->createToken('x');
        $this->withToken($token)->deleteJson('/api/v1/me/tokens/'.$stranger->accessToken->id)->assertStatus(404);
    }
}
