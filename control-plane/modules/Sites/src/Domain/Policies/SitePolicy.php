<?php

namespace Falak\Sites\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

final class SitePolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Site $site): Response
    {
        return $this->check($user, $site, 'sites.view');
    }

    public function update(Authenticatable $user, Site $site): Response
    {
        return $this->check($user, $site, 'sites.manage');
    }

    public function delete(Authenticatable $user, Site $site): Response
    {
        return $this->check($user, $site, 'sites.delete');
    }

    public function revealEnvironment(Authenticatable $user, Site $site): Response
    {
        return $this->check($user, $site, 'sites.env.view');
    }

    public function updateEnvironment(Authenticatable $user, Site $site): Response
    {
        return $this->check($user, $site, 'sites.env.manage');
    }

    public function runCommands(Authenticatable $user, Site $site): Response
    {
        return $this->check($user, $site, 'sites.commands.run');
    }

    private function check(Authenticatable $user, Site $site, string $permission): Response
    {
        if (! $this->access->can($user, $site->organization_id, 'sites.view')) {
            // Do not reveal sites of other organizations.
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $site->organization_id, $permission) ? Response::allow() : Response::deny();
    }
}
