<?php

namespace Falak\Identity\Application\Actions;

use Illuminate\Validation\ValidationException;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Domain\Models\Team;

final class SyncTeamMembers
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  list<string>  $userIds
     */
    public function __invoke(Team $team, array $userIds): void
    {
        $userIds = array_values(array_unique($userIds));

        $members = $team->organization()->firstOrFail()->members()->whereIn('identity_users.id', $userIds)->pluck('identity_users.id')->all();

        if (count($members) !== count($userIds)) {
            throw ValidationException::withMessages(['user_ids' => 'Teams may only contain members of the organization.']);
        }

        $changes = $team->members()->sync($userIds);

        $this->audit->record('team.members_synced', 'team', $team->id, [
            'attached' => $changes['attached'],
            'detached' => $changes['detached'],
        ], $team->organization_id);
    }
}
