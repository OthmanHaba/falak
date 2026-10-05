<?php

namespace Falak\Databases\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Falak\Databases\Application\Actions\EnableContainerAccess;
use Falak\Fleet\Events\AgentVersionChanged;

/**
 * An agent that learned db.containers lets the server's containers reach its localhost engines.
 */
final class EnableContainerAccessOnUpgrade implements ShouldQueue
{
    public function __construct(private readonly EnableContainerAccess $enable) {}

    public function handle(AgentVersionChanged $event): void
    {
        if ($event->serverId !== null) {
            ($this->enable)($event->serverId, $event->features);
        }
    }
}
