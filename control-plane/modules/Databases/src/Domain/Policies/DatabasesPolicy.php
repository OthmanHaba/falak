<?php

namespace Kiln\Databases\Domain\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Kiln\Identity\Contracts\OrganizationAccess;

/**
 * One policy for every organization-scoped Databases model (they all carry organization_id).
 * Resources of other organizations are hidden (404), never merely forbidden.
 */
final class DatabasesPolicy
{
    public const VIEW = 'databases.view';

    public const MANAGE = 'databases.manage';

    public const REVEAL = 'databases.credentials.reveal';

    public const RESTORE = 'databases.restore';

    public const STORAGE = 'databases.storage.manage';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, self::VIEW);
    }

    public function manage(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, self::MANAGE);
    }

    public function reveal(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, self::REVEAL);
    }

    public function restore(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, self::RESTORE);
    }

    public function manageStorage(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, self::STORAGE);
    }

    private function check(Authenticatable $user, Model $model, string $permission): Response
    {
        $organizationId = (string) $model->getAttribute('organization_id');

        if (! $this->access->can($user, $organizationId, self::VIEW)) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $organizationId, $permission) ? Response::allow() : Response::deny();
    }
}
