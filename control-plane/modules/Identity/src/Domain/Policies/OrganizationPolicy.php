<?php

namespace Kiln\Identity\Domain\Policies;

use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Identity\Domain\Models\User;

final class OrganizationPolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function update(User $user, Organization $organization): bool
    {
        return $this->access->can($user, $organization->id, 'organization.update');
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $organization->owner_id === $user->id
            && $this->access->can($user, $organization->id, 'organization.delete');
    }

    public function transfer(User $user, Organization $organization): bool
    {
        return $organization->owner_id === $user->id && ! $organization->personal;
    }

    public function viewMembers(User $user, Organization $organization): bool
    {
        return $this->access->can($user, $organization->id, 'members.view');
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->access->can($user, $organization->id, 'members.manage');
    }

    public function manageTeams(User $user, Organization $organization): bool
    {
        return $this->access->can($user, $organization->id, 'teams.manage');
    }

    public function viewAuditLog(User $user, Organization $organization): bool
    {
        return $this->access->can($user, $organization->id, 'audit.view');
    }
}
