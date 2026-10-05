<?php

namespace Kiln\Edge\Tests\Support;

use Kiln\Servers\Contracts\Data\PhpSettings;
use Kiln\Servers\Contracts\Data\ServerData;
use Kiln\Servers\Contracts\ServerDirectory;

final class FakeServerDirectory implements ServerDirectory
{
    /** @var array<string, ServerData> */
    public array $servers = [];

    public function put(ServerData $server): ServerData
    {
        return $this->servers[$server->id] = $server;
    }

    public function find(string $serverId): ?ServerData
    {
        return $this->servers[$serverId] ?? null;
    }

    public function forOrganization(string $organizationId, ?array $types = null, bool $activeOnly = false): array
    {
        return array_values(array_filter(
            $this->servers,
            fn (ServerData $server) => $server->organizationId === $organizationId && ($types === null || in_array($server->type, $types, true)),
        ));
    }

    public function phpSettings(string $serverId, string $version): ?PhpSettings
    {
        return null;
    }

    public function takenPorts(string $serverId): array
    {
        return [];
    }

    public function installableCaches(string $serverId): array
    {
        return ['redis'];
    }
}
