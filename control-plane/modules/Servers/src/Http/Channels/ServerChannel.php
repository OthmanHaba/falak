<?php

namespace Falak\Servers\Http\Channels;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Servers\Domain\Models\Server;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * private-servers.{serverId}: members of the server's organization with servers.view.
 */
final class ServerChannel
{
    public const NAME = 'servers.{serverId}';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function join(Authenticatable $user, string $serverId): bool
    {
        $organizationId = Server::query()->whereKey($serverId)->value('organization_id');

        return is_string($organizationId) && $this->access->can($user, $organizationId, 'servers.view');
    }
}
