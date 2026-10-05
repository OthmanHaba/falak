<?php

namespace Falak\Identity\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Domain\Models\Team;

final class CreateTeam
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Organization $organization, string $name, ?string $description = null): Team
    {
        $team = $organization->teams()->create(['name' => $name, 'description' => $description]);

        $this->audit->record('team.created', 'team', $team->id, ['name' => $name], $organization->id);

        return $team;
    }
}
