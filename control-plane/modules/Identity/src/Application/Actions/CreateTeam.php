<?php

namespace Kiln\Identity\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Identity\Domain\Models\Team;

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
