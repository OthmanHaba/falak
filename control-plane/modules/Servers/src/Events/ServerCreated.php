<?php

namespace Falak\Servers\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ServerCreated
{
    use Dispatchable;

    public function __construct(
        public string $serverId,
        public string $organizationId,
        public string $type,
        public string $name,
    ) {}
}
