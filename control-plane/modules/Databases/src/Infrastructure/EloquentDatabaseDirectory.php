<?php

namespace Falak\Databases\Infrastructure;

use Falak\Databases\Application\KeyValue\KeyValueSettings;
use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Databases\Domain\Models\Database;

final class EloquentDatabaseDirectory implements DatabaseDirectory
{
    public function find(string $databaseId): ?DatabaseData
    {
        $database = Database::query()->with('databaseServer')->find($databaseId);

        return $database ? $this->toData($database) : null;
    }

    public function findMany(array $databaseIds): array
    {
        if ($databaseIds === []) {
            return [];
        }

        return Database::query()->with('databaseServer')->whereIn('id', array_values(array_unique($databaseIds)))->get()
            ->mapWithKeys(fn (Database $database) => [$database->id => $this->toData($database)])->all();
    }

    public function forOrganization(string $organizationId): array
    {
        return Database::query()->with('databaseServer')->where('organization_id', $organizationId)->orderBy('name')->get()
            ->map(fn (Database $database) => $this->toData($database))->values()->all();
    }

    public function forServer(string $serverId): array
    {
        return Database::query()->with('databaseServer')->where('server_id', $serverId)->orderBy('name')->get()
            ->map(fn (Database $database) => $this->toData($database))->values()->all();
    }

    public function forSite(string $organizationId, string $siteId): array
    {
        return Database::query()->with('databaseServer')->where('organization_id', $organizationId)->where('site_id', $siteId)->orderBy('name')->get()
            ->map(fn (Database $database) => $this->toData($database))->values()->all();
    }

    private function toData(Database $database): DatabaseData
    {
        return new DatabaseData(
            id: $database->id,
            organizationId: $database->organization_id,
            serverId: $database->server_id,
            name: $database->name,
            engine: $database->databaseServer->engine->value,
            engineVersion: $database->databaseServer->version,
            port: $database->port ?? $database->databaseServer->port,
            status: $database->status->value,
            siteId: $database->site_id,
            maxMemoryMb: $database->databaseServer->engine->isKeyValue() ? KeyValueSettings::of($database)['maxmemory_mb'] : null,
        );
    }
}
