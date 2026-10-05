<?php

namespace Falak\Projects\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Projects\Domain\Models\Project;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

final class ProjectPolicy
{
    public const VIEW = 'projects.view';

    public const MANAGE = 'projects.manage';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Project $project): Response
    {
        return $this->check($user, $project, self::VIEW);
    }

    /** Rename, environments, canvas layout, placing new services. */
    public function manage(Authenticatable $user, Project $project): Response
    {
        return $this->check($user, $project, self::MANAGE);
    }

    private function check(Authenticatable $user, Project $project, string $permission): Response
    {
        if (! $this->access->can($user, $project->organization_id, self::VIEW)) {
            // Do not reveal projects of other organizations.
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $project->organization_id, $permission) ? Response::allow() : Response::deny();
    }
}
