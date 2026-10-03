<?php

namespace Kiln\Servers\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Fleet\Events\AgentEnrolled;
use Kiln\Servers\Application\Actions\ProvisionServer;
use Kiln\Servers\Application\Actions\SyncServerSshKeys;
use Kiln\Servers\Application\ServerFacts;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\Models\Server;

final class StartProvisioningOnEnrollment implements ShouldQueue
{
    public function __construct(
        private readonly ServerFacts $facts,
        private readonly ProvisionServer $provision,
        private readonly SyncServerSshKeys $syncKeys,
    ) {}

    public function handle(AgentEnrolled $event): void
    {
        $server = $event->serverId ? Server::query()->find($event->serverId) : null;

        if (! $server || $server->organization_id !== $event->organizationId || $server->status === ServerStatus::Deleting) {
            return;
        }

        $this->facts->record($server, $event->facts);
        $server->forceFill(['install_command' => null])->save();

        // The machine check first (agents with provision.v2), then the plan.
        if (in_array($server->status, ServerStatus::provisionable(), true)) {
            ($this->provision)($server);

            return;
        }

        // Agent reinstalled on an active server: converge authorized_keys again.
        ($this->syncKeys)($server);
    }
}
