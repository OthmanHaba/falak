<?php

namespace Falak\Identity\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Domain\Models\Organization;

final class UpdateOrganization
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Organization $organization, string $name): Organization
    {
        $from = $organization->name;
        $organization->update(['name' => $name]);

        $this->audit->record('organization.updated', 'organization', $organization->id, ['name' => ['from' => $from, 'to' => $name]], $organization->id);

        return $organization;
    }
}
