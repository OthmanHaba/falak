<?php

namespace Falak\Secrets\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Secrets\Domain\Models\Secret;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

final class SecretPolicy
{
    /** Names, metadata, versions (no values), access log, usage. */
    public const VIEW = 'secrets.view';

    /** Read values of non-sensitive secrets (with a recent re-authentication in the browser). */
    public const REVEAL = 'secrets.reveal';

    /** Create, change values, roll back, disable versions, delete. */
    public const MANAGE = 'secrets.manage';

    /** Add, edit, test and delete external secret providers (their credentials and endpoints): admins only. */
    public const PROVIDERS_MANAGE = 'secrets.providers.manage';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Secret $secret): Response
    {
        return $this->check($user, $secret, self::VIEW);
    }

    public function reveal(Authenticatable $user, Secret $secret): Response
    {
        return $this->check($user, $secret, self::REVEAL);
    }

    public function manage(Authenticatable $user, Secret $secret): Response
    {
        return $this->check($user, $secret, self::MANAGE);
    }

    private function check(Authenticatable $user, Secret $secret, string $permission): Response
    {
        if (! $this->access->can($user, $secret->organization_id, self::VIEW)) {
            // Do not reveal secrets of other organizations.
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $secret->organization_id, $permission) ? Response::allow() : Response::deny();
    }
}
