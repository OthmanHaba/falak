<?php

namespace Kiln\Projects\Application\Listeners;

use Kiln\Identity\Events\OrganizationCreated;
use Kiln\Projects\Application\Actions\EnsureDefaultProject;

final class CreateDefaultProject
{
    public function __construct(private readonly EnsureDefaultProject $defaultProject) {}

    public function handle(OrganizationCreated $event): void
    {
        ($this->defaultProject)($event->organizationId, $event->ownerId);
    }
}
