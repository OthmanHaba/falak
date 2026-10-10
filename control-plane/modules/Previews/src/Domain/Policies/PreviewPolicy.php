<?php

namespace Falak\Previews\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Previews and preview settings. Resources of other organizations are hidden (404), never merely forbidden.
 */
final class PreviewPolicy
{
    /** Previews of the organization's projects, their URLs and access credentials. */
    public const VIEW = 'previews.view';

    /** Settings, approving a fork's pull request, redeploying and deleting previews. */
    public const MANAGE = 'previews.manage';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, self::VIEW);
    }

    public function manage(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, self::MANAGE);
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
