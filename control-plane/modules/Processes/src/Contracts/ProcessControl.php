<?php

namespace Kiln\Processes\Contracts;

use Kiln\Fleet\Contracts\Data\CommandHandle;
use Kiln\Processes\Events\ProcessesRestarted;

/**
 * Supervised processes of sites (queue workers, Horizon, Octane, daemons) for other modules.
 *
 * Deployments calls restartForSite() after activating a release (the `$KILN_RESTART_PROCS` step).
 */
interface ProcessControl
{
    /**
     * Gracefully restart the site's programs on its ready servers (or only on $serverId): Horizon gets
     * `horizon:terminate` (the supervisor starts it again), everything else `proc.restart`.
     * Servers without a connected agent are skipped. Emits {@see ProcessesRestarted}.
     *
     * @return list<CommandHandle> the dispatched commands (empty when the site has no running programs)
     */
    public function restartForSite(string $siteId, ?string $serverId = null): array;

    /**
     * Queue a (debounced) convergence of proc.apply / cron.apply for the servers.
     */
    public function converge(string ...$serverIds): void;
}
