<?php

namespace Falak\Servers\Application\Listeners;

use Falak\Fleet\Events\AgentFactsReported;
use Falak\Servers\Application\ServerFacts;
use Falak\Servers\Domain\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;

final class RecordReportedFacts implements ShouldQueue
{
    public function __construct(private readonly ServerFacts $facts) {}

    public function handle(AgentFactsReported $event): void
    {
        $server = $event->serverId ? Server::query()->find($event->serverId) : null;

        if ($server && $server->organization_id === $event->organizationId) {
            $this->facts->record($server, $event->facts);
        }
    }
}
