<?php

namespace Kiln\Databases\Application\Actions;

use Kiln\Databases\Application\KeyValue\ApplyKeyValueInstance;
use Kiln\Databases\Application\KeyValue\KeyValueNetwork;
use Kiln\Databases\Domain\Enums\EngineKind;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Network\Contracts\Firewalls;

/**
 * Lets the containers on a server (compose stacks, Docker sites, functions) reach its localhost database engines once
 * its agent supports it (feature db.containers): re-applies the users with the Docker ranges (users created
 * afterwards get them anyway) and opens the engine ports on the Docker bridges. Idempotent per engine.
 *
 * Redis / Valkey engines follow feature db.redis.network instead (on dedicated cache servers too): their instances
 * are re-applied to listen on the Docker bridge and on the private addresses their environment's other servers use
 * (a restart that keeps the data), after the firewall learned their ports.
 */
final class EnableContainerAccess
{
    public const FEATURE = 'db.containers';

    public function __construct(
        private readonly AgentDirectory $agents,
        private readonly ApplyDatabaseUser $apply,
        private readonly ApplyKeyValueInstance $applyInstance,
        private readonly Firewalls $firewalls,
    ) {}

    /**
     * @param  ?list<string>  $features  the agent's features when known (an AgentVersionChanged event), else looked up
     */
    public function __invoke(string $serverId, ?array $features = null): bool
    {
        $serverId = strtolower($serverId);
        $agent = $features === null ? $this->agents->forServer($serverId) : null;
        $supports = fn (string $feature) => $features !== null ? in_array($feature, $features, true) : ($agent?->supports($feature) ?? false);

        $sql = $supports(self::FEATURE)
            ? DatabaseServer::query()
                ->where('server_id', $serverId)
                ->whereIn('engine', EngineKind::Sql->values())
                ->where('dedicated', false)
                ->where('container_access', false)
                ->get()
            : collect();

        $keyValue = $supports(KeyValueNetwork::FEATURE)
            ? DatabaseServer::query()
                ->where('server_id', $serverId)
                ->whereIn('engine', EngineKind::KeyValue->values())
                ->where('container_access', false)
                ->get()
            : collect();

        // An agent downgraded below db.redis.network: its Redis / Valkey engines go back to loopback only (the payload
        // never carries more for it either, PayloadCompatibility), their instances re-applied, the firewall without their peers.
        $downgraded = $features !== null && ! $supports(KeyValueNetwork::FEATURE)
            ? DatabaseServer::query()
                ->where('server_id', $serverId)
                ->whereIn('engine', EngineKind::KeyValue->values())
                ->where('container_access', true)
                ->get()
            : collect();

        if ($downgraded->isNotEmpty()) {
            foreach ($downgraded as $engine) {
                $engine->forceFill(['container_access' => false])->save();
            }

            $this->firewalls->converge($serverId);

            foreach ($downgraded as $engine) {
                Database::query()
                    ->where('database_server_id', $engine->id)
                    ->whereIn('status', [ResourceStatus::Active, ResourceStatus::Pending])
                    ->orderBy('created_at')
                    ->each(fn (Database $instance) => ($this->applyInstance)($instance, background: true));
            }
        }

        if ($sql->isEmpty() && $keyValue->isEmpty()) {
            return $downgraded->isNotEmpty();
        }

        // Flag and firewall first: users only carry the Docker ranges (which make the agent listen beyond localhost)
        // once container access is on, and the bridge-only rule for the port is queued ahead of them.
        foreach ([...$sql, ...$keyValue] as $engine) {
            $engine->forceFill(['container_access' => true])->save();
        }

        $this->firewalls->converge($serverId);

        foreach ($sql as $engine) {
            DatabaseUser::query()
                ->where('database_server_id', $engine->id)
                ->whereIn('status', [ResourceStatus::Active, ResourceStatus::Pending])
                ->orderBy('created_at')
                ->each(fn (DatabaseUser $user) => ($this->apply)($user, background: true));
        }

        foreach ($keyValue as $engine) {
            Database::query()
                ->where('database_server_id', $engine->id)
                ->whereIn('status', [ResourceStatus::Active, ResourceStatus::Pending])
                ->orderBy('created_at')
                ->each(fn (Database $instance) => ($this->applyInstance)($instance, background: true));
        }

        return true;
    }
}
