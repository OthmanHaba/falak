<?php

namespace Kiln\Identity\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Identity\Domain\Models\Team;
use Kiln\Identity\Domain\Models\User;
use Kiln\Identity\Events\MemberRemoved;

/**
 * Removes a member (or lets a member leave). Revokes their API tokens for the organization.
 */
final class RemoveMember
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly AssignRole $assignRole,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Organization $organization, User $actor, User $member): void
    {
        $memberRole = $this->access->roleOf($member->id, $organization->id);

        if ($memberRole === null) {
            throw ValidationException::withMessages(['member' => 'That user is not a member of this organization.']);
        }

        if ($memberRole === Role::Owner) {
            throw ValidationException::withMessages(['member' => 'The owner cannot be removed; transfer ownership first.']);
        }

        if (! $actor->is($member)) {
            $actorRole = $this->access->roleOf($actor->id, $organization->id);

            if (! $actorRole || ! $actorRole->outranks($memberRole)) {
                throw ValidationException::withMessages(['member' => 'You are not allowed to remove that member.']);
            }
        }

        DB::transaction(function () use ($organization, $member) {
            ($this->assignRole)($member, $organization->id, null);
            $organization->members()->detach($member->id);

            $teamIds = Team::query()->where('organization_id', $organization->id)->pluck('id');
            DB::table('identity_team_members')->whereIn('team_id', $teamIds)->where('user_id', $member->id)->delete();

            $member->tokens()->where('organization_id', $organization->id)->delete();

            if ($member->current_organization_id === $organization->id) {
                $member->forceFill(['current_organization_id' => $member->organizations()->value('identity_organizations.id')])->save();
            }
        });

        $this->audit->record($actor->is($member) ? 'member.left' : 'member.removed', 'user', $member->id, ['role' => $memberRole->value], $organization->id);
        MemberRemoved::dispatch($organization->id, $member->id);
    }
}
