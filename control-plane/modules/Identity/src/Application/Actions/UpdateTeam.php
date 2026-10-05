<?php

namespace Falak\Identity\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Domain\Models\Team;

final class UpdateTeam
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Team $team, string $name, ?string $description): Team
    {
        $team->update(['name' => $name, 'description' => $description]);

        $this->audit->record('team.updated', 'team', $team->id, ['name' => $name], $team->organization_id);

        return $team;
    }
}
