<?php

namespace Falak\Databases\Infrastructure;

use Falak\Databases\Application\Actions\CreateInstance;
use Falak\Databases\Application\Actions\DeleteDatabase;
use Falak\Databases\Application\Actions\RestoreBackup;
use Falak\Databases\Application\Actions\RunDatabaseScript;
use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\Database;
use Falak\Kernel\Security\BackupKeys;
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

    public function restoreLatestBackup(string $sourceDatabaseId, string $targetDatabaseId, ?string $actorId = null): string
    {
        $target = Database::query()->with('instance')->find(strtolower($targetDatabaseId))
            ?? throw ValidationException::withMessages(['database' => 'The database to restore into no longer exists.']);
        $backup = Backup::query()
            ->where('database_id', strtolower($sourceDatabaseId))
            ->where('organization_id', $target->organization_id)
            ->where('type', 'logical')
            ->where('status', BackupStatus::Succeeded)
            ->where('encryption_mode', BackupKeys::CP)
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->get()
            ->first(fn (Backup $backup) => $backup->isRestorable())
            ?? throw ValidationException::withMessages(['backup' => 'The source database has no successful backup Falak can restore (customer-held keys need the key at restore time).']);

        return (app(RestoreBackup::class))($backup, $target->instance, $target->name, $actorId)->id;
    }

    public function runScript(string $databaseId, string $kind, string $script, string $key, int $timeout = 900): string
    {
        $database = Database::query()->with('instance')->find(strtolower($databaseId))
            ?? throw ValidationException::withMessages(['database' => 'The database no longer exists.']);

        return (app(RunDatabaseScript::class))($database, $kind, $script, $key, $timeout)->id;
    }
}
