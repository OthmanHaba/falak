<?php

namespace Falak\Databases\Infrastructure\AgentRequests;

use Falak\Databases\Application\PitrTimeline;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Fleet\Contracts\Exceptions\AgentRequestRefused;

/**
 * The instance an agent's PITR request names: only one of the agent's own server and organization, with PITR on, of
 * the log kind it ships. Anything else is refused the same way (unknown_instance), so an agent learns nothing about
 * instances that are not its own.
 */
trait ResolvesPitrInstance
{
    /**
     * @param  array<string, mixed>  $body
     *
     * @throws AgentRequestRefused
     */
    private function instance(AgentCaller $caller, array $body): DatabaseInstance
    {
        $instance = DatabaseInstance::query()
            ->whereKey(strtolower((string) ($body['instance'] ?? '')))
            ->where('server_id', $caller->serverId)
            ->where('organization_id', $caller->organizationId)
            ->first();

        if ($instance === null || ! $instance->supportsPitr() || PitrTimeline::kind($instance) !== ($body['kind'] ?? null)) {
            throw new AgentRequestRefused('unknown_instance', 'No such database instance on this server.');
        }

        if (! $instance->pitr_enabled || $instance->pitr_storage_provider_id === null || ! in_array($instance->status, [InstanceStatus::Active, InstanceStatus::Upgrading], true)) {
            throw new AgentRequestRefused('pitr_disabled', 'Point-in-time recovery is off for this instance.');
        }

        return $instance;
    }
}
