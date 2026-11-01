<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

class MakeAdminCommand extends Command
{
    protected $signature = 'linklibrary:make-admin {email : E-mail of an existing account} {--revoke : Remove the admin role instead}';

    protected $description = 'Grant (or revoke) the administrator role for an existing account';

    public function handle(AuditLogger $audit): int
    {
        $user = User::query()->where('email', User::normalizeEmail((string) $this->argument('email')))->first();
        if ($user === null) {
            $this->error('No account with that e-mail address.');

            return self::FAILURE;
        }

        $role = $this->option('revoke') ? UserRole::USER : UserRole::ADMIN;
        $user->forceFill(['role' => $role])->save();
        $audit->log('admin.role_changed', null, 'user', $user->public_id, ['role' => $role->value, 'via' => 'console']);
        $this->info("{$user->username} is now {$role->value}.");

        return self::SUCCESS;
    }
}
