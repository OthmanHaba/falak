<?php

namespace Falak\Databases\Application\KeyValue;

use Falak\Databases\Domain\Enums\EngineKind;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Database;
use Falak\Network\Contracts\Firewalls;

/**
 * Keeps an organization's Redis / Valkey instances listening where their consumers are, after something changed who
 * uses them or how servers reach each other (a site placed in or removed from an environment, a site's servers, a
 * private network, a server provisioned): the firewalls of their servers are converged (the peers; a no-op when
 * unchanged), and an instance is re-applied — a restart that keeps the data — only when what it should listen on
 * differs from what was last sent, or when an address it should listen on was missing on the host at the last apply
 * (a private network that has converged since). $docker also re-applies instances that should listen on the Docker
 * bridge but found none (Docker installed after them).
 */
final class ConvergeKeyValueNetwork
{
    public function __construct(
        private readonly KeyValueNetwork $network,
        private readonly ApplyKeyValueInstance $apply,
        private readonly Firewalls $firewalls,
    ) {}

    /**
     * @return int instances re-applied
     */
    public function __invoke(string $organizationId, ?string $serverId = null, bool $docker = false): int
    {
        $instances = Database::query()
            ->with('databaseServer')
            ->where('organization_id', $organizationId)
            ->when($serverId !== null, fn ($q) => $q->where('server_id', strtolower((string) $serverId)))
            ->whereNotNull('port')
            ->whereIn('status', [ResourceStatus::Active, ResourceStatus::Pending])
            ->whereHas('databaseServer', fn ($q) => $q->whereIn('engine', EngineKind::KeyValue->values())->where('container_access', true))
            ->orderBy('created_at')
            ->get();

        $servers = [];
        $stale = [];

        foreach ($instances as $instance) {
            $servers[$instance->server_id] = true;
            $desired = $this->network->desired($instance);
            $wanted = ['bind' => $desired['bind'], 'containers' => $desired['containers']];
            $network = (array) $instance->network;
            $settled = ($network['applied_command'] ?? null) === $instance->command_id && isset($network['bind']);

            $changed = ($network['wanted'] ?? null) !== $wanted;
            $missing = $settled && array_intersect($desired['bind'], (array) ($network['skipped'] ?? [])) !== [];
            $noBridge = $docker && $settled && $desired['containers'] && ($network['container_host'] ?? null) === null;

            if ($changed || $missing || $noBridge) {
                $stale[] = $instance;
            }
        }

        // The firewall first: a new peer is let in before the instance listens for it.
        foreach (array_keys($servers) as $id) {
            $this->firewalls->converge($id);
        }

        foreach ($stale as $instance) {
            ($this->apply)($instance, background: true);
        }

        return count($stale);
    }
}
