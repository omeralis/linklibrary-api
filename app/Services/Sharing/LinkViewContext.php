<?php

namespace App\Services\Sharing;

use App\Models\LinkAccessGrant;
use App\Models\SavedLink;

final class LinkViewContext
{
    public const OWNER = 'owner';

    public const VIEWER = 'viewer';

    public const PUBLIC = 'public';

    public function __construct(
        public readonly SavedLink $savedLink,
        public readonly string $role,
        public readonly ?LinkAccessGrant $grant = null,
    ) {}

    public function isOwner(): bool
    {
        return $this->role === self::OWNER;
    }
}
