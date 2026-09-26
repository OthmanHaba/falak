<?php

namespace Kiln\Identity\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Identity\Domain\Models\User;
use Kiln\Identity\Events\MemberRoleChanged;

final class ChangeMemberRole
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly AssignRole $assignRole,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Organization $organization, User $actor, User $member, Role $role): void
    {
        $actorRole = $this->access->roleOf($actor->id, $organization->id);
        $current = $this->access->roleOf($member->id, $organization->id);

        if ($current === null) {
            throw ValidationException::withMessages(['role' => 'That user is not a member of this organization.']);
        }

        if ($current === Role::Owner || ! in_array($role, Role::assignable(), true)) {
            throw ValidationException::withMessages(['role' => 'Ownership can only be changed by transferring it.']);
        }

        if ($actor->is($member)) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
        }

        // The actor must outrank the member and may not grant a role above their own.
        if (! $actorRole || (! $actorRole->outranks($current) || $role->rank() > $actorRole->rank())) {
            throw ValidationException::withMessages(['role' => 'You are not allowed to assign that role.']);
        }

        if ($current === $role) {
            return;
        }

        ($this->assignRole)($member, $organization->id, $role);

        $this->audit->record('member.role_changed', 'user', $member->id, ['from' => $current->value, 'to' => $role->value], $organization->id);
        MemberRoleChanged::dispatch($organization->id, $member->id, $current->value, $role->value);
    }
}
