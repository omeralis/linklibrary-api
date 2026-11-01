<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => 'u_'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(12)).'@example.com',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password1234'),
            'locale' => 'en',
            'theme' => 'system',
            'profile_public' => false,
            'status' => AccountStatus::ACTIVE,
            'role' => UserRole::USER,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function publicProfile(): static
    {
        return $this->state(fn () => ['profile_public' => true]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::ADMIN]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => AccountStatus::SUSPENDED]);
    }
}
