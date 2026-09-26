<?php

namespace Kiln\Servers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A PHP version was installed, removed or became the CLI default on a server.
 */
final class PhpVersionChanged
{
    use Dispatchable;

    public function __construct(
        public string $serverId,
        public string $organizationId,
        public string $version,
        public string $change,
    ) {}
}
