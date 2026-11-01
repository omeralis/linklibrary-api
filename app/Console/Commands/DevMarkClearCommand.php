<?php

namespace App\Console\Commands;

use App\Enums\SecurityStatus;
use App\Models\Link;
use App\Models\SavedLink;
use App\Models\User;
use App\Services\Security\SecurityScanService;
use Illuminate\Console\Command;

/**
 * LOCAL DEVELOPMENT ONLY. Without a threat-intelligence API key every URL stays UNKNOWN, so the
 * frontend cannot exercise publishing/sharing/discovery. This records a manual CLEAR review verdict
 * (same path as an admin review, audited, expires after SECURITY_CLEAR_TTL_DAYS). Refuses to run
 * outside APP_ENV=local and never touches SUSPICIOUS/MALICIOUS links.
 */
class DevMarkClearCommand extends Command
{
    protected $signature = 'linklibrary:dev-mark-clear
        {publicId? : Saved link public id}
        {--all-unknown : Every PENDING/UNKNOWN link}
        {--admin= : E-mail of the reviewing admin account (default: first active admin)}';

    protected $description = '[local only] Mark saved links CLEAR via a manual review so publish/share flows can be developed';

    public function handle(SecurityScanService $security): int
    {
        if (! app()->environment('local')) {
            $this->error('Refusing: this command only runs with APP_ENV=local.');

            return self::FAILURE;
        }

        $admin = $this->option('admin')
            ? User::query()->where('email', User::normalizeEmail((string) $this->option('admin')))->where('role', 'ADMIN')->first()
            : User::query()->where('role', 'ADMIN')->where('status', 'ACTIVE')->orderBy('id')->first();
        if ($admin === null) {
            $this->error('No admin account found (php artisan linklibrary:make-admin you@example.com).');

            return self::FAILURE;
        }

        $query = Link::query()->whereIn('security_status', [SecurityStatus::PENDING->value, SecurityStatus::UNKNOWN->value]);
        if ($this->argument('publicId') !== null) {
            $linkId = SavedLink::isValidPublicId((string) $this->argument('publicId'))
                ? SavedLink::withTrashed()->where('public_id', $this->argument('publicId'))->value('link_id')
                : null;
            if ($linkId === null) {
                $this->error('Saved link not found.');

                return self::FAILURE;
            }
            $query->whereKey($linkId);
        } elseif (! $this->option('all-unknown')) {
            $this->error('Pass a saved link public id or --all-unknown.');

            return self::FAILURE;
        }

        $count = 0;
        foreach ($query->get() as $link) {
            $security->applyManualVerdict($link, SecurityStatus::CLEAR, $admin, 'local development');
            $count++;
        }
        $this->info("{$count} link(s) marked CLEAR (manual review by {$admin->email}).");

        return self::SUCCESS;
    }
}
