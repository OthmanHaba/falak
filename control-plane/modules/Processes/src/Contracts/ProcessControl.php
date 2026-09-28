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
     * Octane: after a deploy ($newRelease) the program is restarted — `octane:reload` would re-boot the workers
     * from the release directory the server was started in (Octane resolves `current` once, at start), i.e. keep
     * serving the old code. The edge holds requests while it restarts (reverse_proxy retries for 30s), so no
     * request fails. Without a new release (restart from the UI) a verified-listening Octane gets
     * `octane:reload` (graceful worker reload, the port never closes), falling back to `proc.restart`.
     *
     * @return list<CommandHandle> the dispatched commands (empty when the site has no running programs)
     */
    public function restartForSite(string $siteId, ?string $serverId = null, bool $newRelease = true): array;

    /**
     * Queue a (debounced) convergence of proc.apply / cron.apply for the servers.
     */
    public function converge(string ...$serverIds): void;
}
