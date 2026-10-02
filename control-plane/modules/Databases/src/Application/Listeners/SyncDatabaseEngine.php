<?php

namespace Kiln\Databases\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Databases\Application\Actions\EnableContainerAccess;
use Kiln\Databases\Application\EngineInventory;
use Kiln\Servers\Events\DatabaseEngineInstalled;
use Kiln\Servers\Events\ServerProvisioned;

/**
 * Registers the server's database engine once provisioning converged.
 */
final class SyncDatabaseEngine implements ShouldQueue
{
    public function __construct(
        private readonly EngineInventory $inventory,
        private readonly EnableContainerAccess $containers,
    ) {}

    public function handle(ServerProvisioned $event): void
    {
        $this->inventory->sync($event->serverId);
        ($this->containers)($event->serverId);
    }

    /** An engine added to a provisioned server: registered, and reachable from its containers, like one from creation. */
    public function installed(DatabaseEngineInstalled $event): void
    {
        $this->inventory->sync($event->serverId);
        ($this->containers)($event->serverId);
    }
}
