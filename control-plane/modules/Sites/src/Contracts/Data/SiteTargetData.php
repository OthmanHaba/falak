<?php

namespace Kiln\Sites\Contracts\Data;

use Kiln\Sites\Contracts\TargetRole;
use Kiln\Sites\Contracts\TargetStatus;

final readonly class SiteTargetData
{
    public function __construct(
        public string $id,
        public string $siteId,
        public string $serverId,
        public TargetRole $role,
        public TargetStatus $status,
    ) {}

    public function isLeader(): bool
    {
        return $this->role === TargetRole::Leader;
    }
}
