<?php

namespace Falak\Volumes\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * One policy for every organization-scoped Volumes model (volumes, backups, schedules, operations).
 * Resources of other organizations are hidden (404), never merely forbidden.
 */
final class VolumePolicy
{
    /** Volumes, their usage, attachments and backups. */
    public const VIEW = 'volumes.view';

    /** Create, attach, detach, resize, back up, restore, clone, move and delete. */
    public const MANAGE = 'volumes.manage';

    /** The file browser and downloads: they read the data itself (audited). */
    public const BROWSE = 'volumes.browse';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, self::VIEW);
    }

    public function manage(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, self::MANAGE);
    }

    public function browse(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, self::BROWSE);
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
