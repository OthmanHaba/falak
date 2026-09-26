<?php

namespace Kiln\Databases\Application;

use Kiln\Databases\Domain\Enums\Engine;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Servers\Contracts\ServerType;

/**
 * Derives the database engine of each server from its provisioned stack (engine) and the agent's
 * facts (facts.runtimes.<engine> → version), falling back to the distro package version.
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
            if ($row = $this->syncData($server)) {
                $synced[] = $row;
            }
        }

        return $synced;
    }

    public function sync(string $serverId): ?DatabaseServer
    {
        $server = $this->servers->find($serverId);

        return $server ? $this->syncData($server) : null;
    }

    private function syncData(ServerData $server): ?DatabaseServer
    {
        $engine = Engine::fromStack($server->databaseEngine);
        $existing = DatabaseServer::query()->where('server_id', $server->id)->first();

        if ($engine === null) {
            // The stack no longer declares an engine; keep rows that still hold resources.
            return $existing;
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
            'dedicated' => $server->type === ServerType::Database,
            'port' => $existing?->engine === $engine ? $existing->port : $engine->defaultPort(),
        ]);

        if ($row->isDirty()) {
            $row->save();
        }

        return $row;
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
        $distro = (array) ($distros[$os] ?? $distros['ubuntu 24.04'] ?? []);

        return [isset($distro[$engine->value]) ? (string) $distro[$engine->value] : null, 'default'];
    }

    public static function normalizeVersion(Engine $engine, string $version): string
    {
        if (preg_match('/(\d+)(?:\.(\d+))?/', $version, $m) !== 1) {
            return $version;
        }

        return $engine === Engine::PostgreSql || ! isset($m[2]) ? $m[1] : "{$m[1]}.{$m[2]}";
    }
}
