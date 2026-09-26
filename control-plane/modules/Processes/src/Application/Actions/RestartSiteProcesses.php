<?php

namespace Kiln\Processes\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Processes\Contracts\ProcessControl;
use Kiln\Sites\Contracts\Data\SiteData;

/**
 * Restart a site's programs from the UI.
 */
final class RestartSiteProcesses
{
    public function __construct(
        private readonly ProcessControl $processes,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return int number of commands dispatched
     */
    public function __invoke(SiteData $site, ?string $serverId): int
    {
        $handles = $this->processes->restartForSite($site->id, $serverId);

        $this->audit->record('processes.restarted', 'site', $site->id, ['server_id' => $serverId, 'commands' => count($handles)], $site->organizationId);

        return count($handles);
    }
}
