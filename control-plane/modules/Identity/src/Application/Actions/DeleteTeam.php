<?php

namespace Falak\Identity\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Domain\Models\Team;

final class DeleteTeam
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Team $team): void
    {
        $team->delete();

        $this->audit->record('team.deleted', 'team', $team->id, ['name' => $team->name], $team->organization_id);
    }
}
