<?php

namespace Kiln\Databases\Application;

use Kiln\Databases\Domain\Enums\Engine;
use Kiln\Databases\Domain\Enums\EngineKind;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Servers\Contracts\ServerType;

/**
 * Derives the database engines of each server from its provisioned stack (SQL engine, and Redis / Valkey from the
 * cache component) and the agent's facts (facts.runtimes.<engine> → version), falling back to the distro package
 * version.
 */
final class EngineInventory
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly AgentDirectory $agents,
    ) {}

    /**
     * @return list<DatabaseServer>
     */
    public function syncOrganization(string $organizationId): array
    {
        $synced = [];

        foreach ($this->servers->forOrganization($organizationId) as $server) {
            array_push($synced, ...array_values($this->syncData($server)));
        }

        return $synced;
    }

    /**
     * Syncs the server's engines and returns the row of $engine, or of its SQL engine when null.
     */
    public function sync(string $serverId, ?Engine $engine = null): ?DatabaseServer
    {
        $server = $this->servers->find($serverId);

        if ($server === null) {
            return null;
        }

        $rows = $this->syncData($server);

        return $engine !== null
            ? ($rows[$engine->value] ?? DatabaseServer::query()->where('server_id', $serverId)->where('engine', $engine)->first())
            : $this->sqlRow($rows);
    }

    /**
     * One row per engine kind: the SQL engine (ServerData::databaseEngine) and the key-value engine (cacheEngine).
     *
     * @return array<string, DatabaseServer> keyed by engine value
     */
    private function syncData(ServerData $server): array
    {
        $rows = [];

        foreach ([[EngineKind::Sql, $server->databaseEngine], [EngineKind::KeyValue, $server->cacheEngine]] as [$kind, $stack]) {
            if ($row = $this->syncKind($server, $kind, Engine::fromStack($stack))) {
                $rows[$row->engine->value] = $row;
            }
        }

        return $rows;
    }

    private function syncKind(ServerData $server, EngineKind $kind, ?Engine $engine): ?DatabaseServer
    {
        if ($engine !== null && $engine->kind() !== $kind) {
            $engine = null;
        }

        $existing = DatabaseServer::query()->where('server_id', $server->id)->whereIn('engine', $kind->values())
            ->when($engine !== null, fn ($q) => $q->orderByRaw('case when engine = ? then 0 else 1 end', [$engine->value]))
            ->first();

        if ($engine === null) {
            // The stack no longer declares an engine; keep rows that still hold resources.
            return $existing;
        }

        // A key-value engine row of another engine (Valkey replaced Redis) keeps its instances: a new row is added.
        if ($kind === EngineKind::KeyValue && $existing !== null && $existing->engine !== $engine) {
            $existing = null;
        }

        $facts = $this->agents->forServer($server->id)?->facts ?? [];
        [$version, $source] = $this->detectVersion($engine, $facts);

        if ($existing && $existing->engine === $engine && $existing->version_source === 'manual') {
            [$version, $source] = [$existing->version, 'manual'];
        }

        $row = $existing ?? new DatabaseServer(['server_id' => $server->id]);
        $row->fill([
            'organization_id' => $server->organizationId,
            'server_name' => $server->name,
            'engine' => $engine,
            'version' => $version,
            'version_source' => $source,
            'dedicated' => $kind === EngineKind::Sql ? $server->type === ServerType::Database : $server->type === ServerType::Cache,
            'port' => $existing?->engine === $engine ? $existing->port : $engine->defaultPort(),
        ]);

        if ($row->isDirty()) {
            $row->save();
        }

        return $row;
    }

    /**
     * @param  array<string, DatabaseServer>  $rows
     */
    private function sqlRow(array $rows): ?DatabaseServer
    {
        foreach ($rows as $row) {
            if ($row->engine->kind() === EngineKind::Sql) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{0: ?string, 1: string}
     */
    public function detectVersion(Engine $engine, array $facts): array
    {
        $runtimes = is_array($facts['runtimes'] ?? null) ? $facts['runtimes'] : [];

        foreach ($engine->factKeys() as $key) {
            $versions = $runtimes[$key] ?? null;

            if (is_array($versions) && $versions !== [] && is_string($versions[0] ?? null)) {
                return [self::normalizeVersion($engine, $versions[0]), 'facts'];
            }
        }

        $os = is_array($facts['os'] ?? null) ? strtolower(trim(($facts['os']['id'] ?? '').' '.($facts['os']['version'] ?? ''))) : '';
        // Keys contain dots ("ubuntu 24.04"), so no dot-notation lookups here.
        $distros = (array) config('databases.distro_versions', []);
        $version = ((array) ($distros[$os] ?? []))[$engine->value] ?? ((array) ($distros['ubuntu 24.04'] ?? []))[$engine->value] ?? null;

        return [$version !== null ? (string) $version : null, 'default'];
    }

    public static function normalizeVersion(Engine $engine, string $version): string
    {
        if (preg_match('/(\d+)(?:\.(\d+))?/', $version, $m) !== 1) {
            return $version;
        }

        return $engine === Engine::PostgreSql || ! isset($m[2]) ? $m[1] : "{$m[1]}.{$m[2]}";
    }
}
