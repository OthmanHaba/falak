<?php

namespace Falak\Processes\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Processes\Contracts\ProcessControl;
use Falak\Sites\Contracts\Data\SiteData;

/**
 * Restart a site's programs from the UI (same release: Octane gets a graceful `octane:reload`).
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
        $handles = $this->processes->restartForSite($site->id, $serverId, newRelease: false);

        $this->audit->record('processes.restarted', 'site', $site->id, ['server_id' => $serverId, 'commands' => count($handles)], $site->organizationId);

        return count($handles);
    }
}
