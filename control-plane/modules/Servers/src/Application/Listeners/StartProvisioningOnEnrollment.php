<?php

namespace Kiln\Servers\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Fleet\Events\AgentEnrolled;
use Kiln\Servers\Application\Actions\ApplyProvisioningPlan;
use Kiln\Servers\Application\Actions\SyncServerSshKeys;
use Kiln\Servers\Application\ServerFacts;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\Models\Server;

final class StartProvisioningOnEnrollment implements ShouldQueue
{
    public function __construct(
        private readonly ServerFacts $facts,
        private readonly ApplyProvisioningPlan $apply,
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

        if (in_array($server->status, [ServerStatus::Creating, ServerStatus::Provisioning, ServerStatus::Error], true)) {
            ($this->apply)($server);

            return;
        }

        // Agent reinstalled on an active server: converge authorized_keys again.
        ($this->syncKeys)($server);
    }
}
