<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $username
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $avatar_url
 * @property string|null $avatar_path
 * @property string|null $bio
 * @property string $locale
 * @property string $theme
 * @property bool $profile_public
 * @property AccountStatus $status
 * @property UserRole $role
 * @property Carbon|null $password_changed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Mass assignment is restricted to profile fields. Security-relevant attributes (role, status,
     * email_verified_at, password) are always set explicitly by actions.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'bio',
        'locale',
        'theme',
        'profile_public',
    ];

    /** @var list<string> */
    protected $hidden = [
        'id',
        'password',
        'remember_token',
        'email',
        'role',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->public_id ??= (string) Str::uuid();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
            'profile_public' => 'boolean',
            'status' => AccountStatus::class,
            'role' => UserRole::class,
        ];
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function normalizeUsername(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::ACTIVE;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    /** @return HasMany<SavedLink, $this> */
    public function savedLinks(): HasMany
    {
        return $this->hasMany(SavedLink::class);
    }

    /** @return HasMany<Collection, $this> */
    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /** @param string $token */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * ID of the personal access token used for this request, or null for cookie (session) auth
     * (Sanctum then provides a TransientToken).
     */
    public function currentPersonalAccessTokenId(): ?int
    {
        $token = $this->accessToken;

        return is_object($token) && is_a($token, PersonalAccessToken::class) ? (int) $token->getKey() : null;
    }

    public function preferredLocale(): string
    {
        return $this->locale;
    }
}
