<?php

namespace Falak\Servers\Infrastructure;

use Falak\Servers\Contracts\Data\PhpSettings;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Models\MachineInspection;
use Falak\Servers\Domain\Models\Server;

final class EloquentServerDirectory implements ServerDirectory
{
    public function find(string $serverId): ?ServerData
    {
        return Server::query()->with('phpVersions')->find($serverId)?->toData();
    }

    public function forOrganization(string $organizationId, ?array $types = null, bool $activeOnly = false): array
    {
        return Server::query()
            ->with('phpVersions')
            ->where('organization_id', $organizationId)
            ->when($types !== null, fn ($q) => $q->whereIn('type', array_map(fn (ServerType $type) => $type->value, $types)))
            ->when($activeOnly, fn ($q) => $q->where('status', ServerStatus::Active))
            ->orderBy('name')
            ->get()
            ->map(fn (Server $server) => $server->toData())
            ->values()
            ->all();
    }

    public function phpSettings(string $serverId, string $version): ?PhpSettings
    {
        $server = Server::query()->find($serverId);

        return $server?->phpVersions()->where('version', $version)->where('status', PhpVersionStatus::Installed)->first()?->toSettings();
    }

    public function takenPorts(string $serverId): array
    {
        $report = MachineInspection::query()->where('server_id', strtolower($serverId))->first()?->report;

        if (! is_array($report)) {
            return [];
        }

        $ports = [];

        foreach ((array) ($report['listeners'] ?? []) as $listener) {
            if (is_array($listener) && is_numeric($listener['port'] ?? null)) {
                $ports[] = (int) $listener['port'];
            }
        }

        foreach ((array) ($report['containers'] ?? []) as $container) {
            foreach (is_array($container) ? (array) ($container['ports'] ?? []) : [] as $published) {
                if (is_array($published) && is_numeric($published['host_port'] ?? null)) {
                    $ports[] = (int) $published['host_port'];
                }
            }
        }

        $ports = array_values(array_unique($ports));
        sort($ports);

        return $ports;
    }
}
