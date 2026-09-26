<?php

namespace Kiln\Identity\Contracts;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Organization-scoped authorization. Every module's policies delegate here, e.g.
 * `$access->can($user, $server->organization_id, 'servers.update')`.
 *
 * When the user authenticated with an API token, the token must also belong to the
 * organization and carry the ability (or "*").
 */
interface OrganizationAccess
{
    public function can(?Authenticatable $user, string $organizationId, string $permission): bool;

    /**
     * @throws AuthorizationException
     */
    public function authorize(?Authenticatable $user, string $organizationId, string $permission): void;

    public function isMember(string $userId, string $organizationId): bool;

    public function roleOf(string $userId, string $organizationId): ?Role;

    /**
     * @return list<string> user ids
     */
    public function memberIds(string $organizationId): array;
}
