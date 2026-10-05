<?php

namespace Falak\Sites\Contracts\Data;

use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;

final readonly class SiteTargetData
{
    public function __construct(
        public string $id,
        public string $siteId,
        public string $serverId,
        public TargetRole $role,
        public TargetStatus $status,
        /** Why preparation failed (when $status is Failed). */
        public ?string $statusMessage = null,
    ) {}

    public function isLeader(): bool
    {
        return $this->role === TargetRole::Leader;
    }
}
