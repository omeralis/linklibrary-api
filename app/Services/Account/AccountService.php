<?php

namespace App\Services\Account;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\SavedLink;
use App\Models\User;
use App\Notifications\Auth\EmailChangedNotification;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Services\Discovery\DiscoveryCache;
use App\Services\Sharing\AccessGraphService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessGraphService $access,
        private readonly DiscoveryCache $discoveryCache,
    ) {}

    /** @param array{name: string, username: string, email: string, password: string, locale?: string|null} $data */
    public function register(array $data): User
    {
        $user = new User;
        $user->forceFill([
            'name' => trim($data['name']),
            'username' => User::normalizeUsername($data['username']),
            'email' => User::normalizeEmail($data['email']),
            'password' => $data['password'],
            'locale' => $data['locale'] ?? app()->getLocale(),
            'theme' => 'system',
            'profile_public' => false,
            'status' => AccountStatus::ACTIVE,
            'role' => UserRole::USER,
            'password_changed_at' => now(),
        ])->save();

        $user->sendEmailVerificationNotification();
        $this->audit->log(AuditAction::USER_REGISTERED, $user, 'user', $user->public_id);

        return $user;
    }

    public function assertPassword(User $user, ?string $password, string $field = 'current_password'): void
    {
        if ($password === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([$field => [__('api.auth.password_incorrect')]]);
        }
    }

    /** @param array<string, mixed> $data */
    public function updateProfile(User $user, array $data): User
    {
        $fill = array_intersect_key($data, array_flip(['name', 'bio', 'locale', 'theme', 'profile_public']));
        if (isset($data['username'])) {
            $fill['username'] = User::normalizeUsername((string) $data['username']);
        }

        $emailChanged = false;
        $oldEmail = $user->email;
        if (isset($data['email']) && User::normalizeEmail((string) $data['email']) !== $user->email) {
            // Changing the login e-mail is a sensitive operation: requires the current password.
            $this->assertPassword($user, isset($data['current_password']) ? (string) $data['current_password'] : null);
            $fill['email'] = User::normalizeEmail((string) $data['email']);
            $fill['email_verified_at'] = null;
            $emailChanged = true;
        }

        $wasPublic = $user->profile_public;
        $user->forceFill($fill)->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
            Notification::route('mail', $oldEmail)->notify(new EmailChangedNotification);
            $this->audit->log(AuditAction::EMAIL_CHANGED, $user, 'user', $user->public_id);
        }
        if ($wasPublic !== $user->profile_public) {
            $this->discoveryCache->invalidate();
        }

        return $user;
    }

    public function changePassword(User $user, string $current, string $new, ?string $keepSessionId = null, ?int $keepTokenId = null): void
    {
        $this->assertPassword($user, $current);

        $user->forceFill(['password' => $new, 'password_changed_at' => now()])->setRememberToken(null);
        $user->save();

        $this->revokeOtherSessions($user, $keepSessionId, $keepTokenId);
        $user->notify(new PasswordChangedNotification);
        $this->audit->log(AuditAction::PASSWORD_CHANGED, $user, 'user', $user->public_id);
    }

    /** Sign out everywhere else (database sessions and API tokens). */
    public function revokeOtherSessions(User $user, ?string $keepSessionId = null, ?int $keepTokenId = null): void
    {
        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($keepSessionId !== null, fn ($q) => $q->where('id', '!=', $keepSessionId))
                ->delete();
        }
        $user->tokens()->when($keepTokenId !== null, fn ($q) => $q->where('id', '!=', $keepTokenId))->delete();
    }

    public function deleteAccount(User $user, string $password): void
    {
        $this->assertPassword($user, $password, 'password');

        // Revoke access the user holds (cascades to grants they issued downstream).
        $this->access->revokeAllHeldBy($user, 'ACCOUNT_DELETED');

        // Invalidate every grant/invitation on the user's own links before rows are removed.
        SavedLink::withTrashed()->where('user_id', $user->id)->each(function (SavedLink $savedLink) use ($user) {
            $this->access->revokeAllForSavedLink($savedLink, $user, 'ACCOUNT_DELETED');
        });

        $this->audit->log(AuditAction::ACCOUNT_DELETED, $user, 'user', $user->public_id);

        DB::transaction(function () use ($user) {
            $this->revokeOtherSessions($user);
            if ($user->avatar_path !== null) {
                Storage::disk((string) config('linklibrary.uploads.disk'))->delete($user->avatar_path);
            }
            $paths = SavedLink::withTrashed()->where('user_id', $user->id)->whereNotNull('custom_image_path')->pluck('custom_image_path')->all();
            if ($paths !== []) {
                Storage::disk((string) config('linklibrary.uploads.disk'))->delete($paths);
            }
            DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->delete();
            $user->delete(); // cascades: saved links, collections, grants, invitations
        });

        $this->discoveryCache->invalidate();
    }

    public function ensureActive(User $user): void
    {
        if (! $user->isActive()) {
            throw new ApiException('ACCOUNT_SUSPENDED', 403, 'api.auth.suspended');
        }
    }
}
