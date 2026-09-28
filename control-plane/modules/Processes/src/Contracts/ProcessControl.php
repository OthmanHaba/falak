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
     * Restart the site's programs on its ready servers (or only on $serverId) on their live release. The server is
     * converged first: a proc.apply carrying changed definitions (after a deploy: the new KILN_RELEASE_ID and the
     * release's environment) restarts those programs itself — and starts them on a site's first deploy. Running
     * programs it leaves unchanged are restarted gracefully: Horizon with `horizon:terminate` (the supervisor
     * starts it again), everything else with `proc.restart`.
     * Servers without a connected agent are skipped. Emits {@see ProcessesRestarted}.
     *
     * @return list<CommandHandle> the dispatched commands (empty when the site has no programs to restart)
     */
    public function restartForSite(string $siteId, ?string $serverId = null): array;

    /**
     * Queue a (debounced) convergence of proc.apply / cron.apply for the servers.
     */
    public function converge(string ...$serverIds): void;
}
