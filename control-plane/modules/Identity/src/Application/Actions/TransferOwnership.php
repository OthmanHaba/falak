<?php

namespace Falak\Identity\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Domain\Models\User;
use Falak\Identity\Events\MemberRoleChanged;

final class TransferOwnership
{
    public function __construct(
        private readonly AssignRole $assignRole,
        private readonly OrganizationAccess $access,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Organization $organization, User $currentOwner, User $newOwner): void
    {
        if ($organization->owner_id !== $currentOwner->id) {
            throw ValidationException::withMessages(['user_id' => 'Only the owner can transfer ownership.']);
        }

        if ($organization->personal) {
            throw ValidationException::withMessages(['user_id' => 'Personal organizations cannot be transferred.']);
        }

        if ($newOwner->is($currentOwner) || ! $newOwner->belongsToOrganization($organization->id)) {
            throw ValidationException::withMessages(['user_id' => 'The new owner must be another member of this organization.']);
        }

        $previousRole = $this->access->roleOf($newOwner->id, $organization->id) ?? Role::Viewer;

        DB::transaction(function () use ($organization, $currentOwner, $newOwner) {
            $organization->update(['owner_id' => $newOwner->id]);
            ($this->assignRole)($newOwner, $organization->id, Role::Owner);
            ($this->assignRole)($currentOwner, $organization->id, Role::Admin);
        });

        $this->audit->record('organization.ownership_transferred', 'organization', $organization->id, [
            'from' => $currentOwner->id,
            'to' => $newOwner->id,
        ], $organization->id);

        MemberRoleChanged::dispatch($organization->id, $newOwner->id, $previousRole->value, Role::Owner->value);
        MemberRoleChanged::dispatch($organization->id, $currentOwner->id, Role::Owner->value, Role::Admin->value);
    }
}
