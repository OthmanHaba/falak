<?php

namespace Kiln\Servers\Infrastructure;

use Kiln\Servers\Contracts\Data\PhpSettings;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Enums\PhpVersionStatus;
use Kiln\Servers\Domain\Models\Server;

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
}
