<?php

namespace Falak\Databases\Application\Listeners;

use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Servers\Events\DatabaseEngineInstallFailed;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An engine added to a server never got installed: drop its row (registered from the stack meanwhile) unless it
 * holds databases or users.
 */
final class ForgetFailedEngine implements ShouldQueue
{
    public function handle(DatabaseEngineInstallFailed $event): void
    {
        $engine = Engine::fromStack($event->engine);
        if ($engine === null) {
            return;
        }

        $row = DatabaseServer::query()->where('server_id', $event->serverId)->where('organization_id', $event->organizationId)->where('engine', $engine)->first();

        if ($row === null || $row->databases()->exists() || $row->users()->exists()) {
            return;
        }

        $row->delete();
    }
}
