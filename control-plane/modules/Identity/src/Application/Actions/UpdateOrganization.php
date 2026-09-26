<?php

namespace Kiln\Identity\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Domain\Models\Organization;

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
