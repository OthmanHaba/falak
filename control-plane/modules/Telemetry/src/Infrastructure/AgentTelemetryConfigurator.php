<?php

namespace Kiln\Telemetry\Infrastructure;

use Illuminate\Support\Str;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Telemetry\Contracts\ServerSites;
use Kiln\Telemetry\Contracts\TelemetryConfigurator;
use Kiln\Telemetry\Domain\Models\TelemetrySettings;

final class AgentTelemetryConfigurator implements TelemetryConfigurator
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
        private readonly ServerSites $sites,
    ) {}

    public function reconfigure(string $serverId): ?string
    {
        $server = $this->servers->find($serverId);

        if (! $server) {
            return null;
        }

        $payload = TelemetryPayload::build(
            $server->organizationId,
            $server->id,
            TelemetrySettings::for($server->organizationId),
            $this->sites->forServer($server->id),
        );

        try {
            // One logical operation per call: the agent answers repeated keys from its result cache.
            return $this->agents->dispatch($server->id, 'telemetry.configure', $payload, 120, "telemetry.configure:{$server->id}:".Str::ulid())->id;
        } catch (AgentUnavailable) {
            return null;
        }
    }

    public function reconfigureOrganization(string $organizationId): int
    {
        $count = 0;

        foreach ($this->servers->forOrganization($organizationId, activeOnly: true) as $server) {
            if ($this->reconfigure($server->id) !== null) {
                $count++;
            }
        }

        return $count;
    }
}
