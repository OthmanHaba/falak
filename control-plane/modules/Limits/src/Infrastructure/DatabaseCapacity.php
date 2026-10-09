<?php

namespace Falak\Limits\Infrastructure;

use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Limits\Contracts\CapacitySource;
use Falak\Limits\Contracts\Data\CapacityItem;

/**
 * Database instances: one container each, with the memory (and CPU) limit set when it was created. The directory
 * lists databases; their instances are counted once.
 */
final class DatabaseCapacity implements CapacitySource
{
    public function __construct(private readonly DatabaseDirectory $databases) {}

    public function onServer(string $organizationId, string $serverId): array
    {
        $items = [];

        foreach ($this->databases->forServer($serverId) as $database) {
            if ($database->organizationId !== $organizationId || $database->status === 'deleting') {
                continue;
            }

            $id = $database->instanceId ?? $database->id;
            $items[$id] ??= new CapacityItem('database', $id, $database->name, $database->memoryMb, $database->memoryMb, $database->cpus, "/databases/{$database->id}");
        }

        return array_values($items);
    }
}
