<?php

namespace Falak\Databases\Infrastructure;

use Falak\Databases\Application\Actions\CreateInstance;
use Falak\Databases\Application\Actions\DeleteDatabase;
use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Models\Database;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ActionDatabaseProvisioner implements DatabaseProvisioner
{
    public function __construct(
        private readonly CreateInstance $createInstance,
        private readonly DatabaseDirectory $directory,
        private readonly DeleteDatabase $deleteDatabase,
    ) {}

    public function create(string $organizationId, string $serverId, string $engine, string $name, ?string $actorId = null, array $options = []): DatabaseData
    {
        $requested = Engine::tryFrom(strtolower($engine))
            ?? throw ValidationException::withMessages(['engine' => 'Unknown database engine.']);

        $instance = ($this->createInstance)($organizationId, $serverId, $requested, [
            'name' => $name,
            // A compose image's tag ("16-alpine") picks the closest major Falak offers.
            'version' => $options['version'] ?? (isset($options['image_tag']) ? $requested->versionFromTag($options['image_tag']) : null),
            'database' => $options['database'] ?? null,
            'memory_mb' => $options['memory_mb'] ?? null,
            'cpus' => $options['cpus'] ?? null,
            'disk_gb' => $options['disk_gb'] ?? null,
            'environment_id' => $options['environment_id'] ?? null,
            'settings' => array_filter(array_intersect_key($options, array_flip(['eviction', 'persistence', 'max_connections'])), fn ($value) => $value !== null),
        ], $actorId);

        $database = $instance->databases()->reorder()->orderBy('created_at')->firstOrFail();

        return $this->directory->find($database->id) ?? throw new LogicException('Created database not found.');
    }

    public function delete(string $databaseId, bool $deleteVolume = false): void
    {
        if ($database = Database::query()->find(strtolower($databaseId))) {
            ($this->deleteDatabase)($database, withInstance: true, deleteVolume: $deleteVolume);
        }
    }
}
