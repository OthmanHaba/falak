<?php

namespace Falak\Secrets\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Secrets\Domain\Models\SecretProvider;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Providers are seen with secrets.view (names, types, status; never credentials) and changed with secrets.manage.
 */
final class SecretProviderPolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, SecretProvider $provider): Response
    {
        return $this->check($user, $provider, SecretPolicy::VIEW);
    }

    public function manage(Authenticatable $user, SecretProvider $provider): Response
    {
        return $this->check($user, $provider, SecretPolicy::MANAGE);
    }

    private function check(Authenticatable $user, SecretProvider $provider, string $permission): Response
    {
        if (! $this->access->can($user, $provider->organization_id, SecretPolicy::VIEW)) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $provider->organization_id, $permission) ? Response::allow() : Response::deny();
    }
}
