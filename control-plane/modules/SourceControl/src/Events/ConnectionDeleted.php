<?php

namespace Falak\SourceControl\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ConnectionDeleted
{
    use Dispatchable;

    public function __construct(
        public string $connectionId,
        public string $organizationId,
        public string $provider,
    ) {}
}
