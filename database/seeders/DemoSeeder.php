<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local development accounts only (never runs outside APP_ENV=local). Passwords are documented in
 * README for local use and must never be used in any shared environment.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['admin', 'admin@linklibrary.test', 'Demo Admin', UserRole::ADMIN],
            ['demo_user', 'demo@linklibrary.test', 'Demo User', UserRole::USER],
        ] as [$username, $email, $name, $role]) {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill([
                'name' => $name,
                'username' => $username === 'admin' ? 'demo_admin' : $username,
                'password' => Hash::make('Password12345'),
                'email_verified_at' => now(),
                'role' => $role,
                'status' => 'ACTIVE',
                'profile_public' => true,
            ])->save();
        }
    }
}
