<?php

namespace Kiln\Servers\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Fleet\Events\AgentFactsReported;
use Kiln\Servers\Application\ServerFacts;
use Kiln\Servers\Domain\Models\Server;

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
