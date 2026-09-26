<?php

namespace Kiln\Processes\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The programs of a site were asked to restart (after a deploy or from the UI).
 */
final class ProcessesRestarted
{
    use Dispatchable;

    /**
     * @param  list<string>  $serverIds
     * @param  list<string>  $commandIds  proc.restart / horizon:terminate commands
     */
    public function __construct(
        public string $siteId,
        public string $organizationId,
        public array $serverIds,
        public array $commandIds,
    ) {}
}
