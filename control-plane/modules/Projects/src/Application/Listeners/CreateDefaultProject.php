<?php

namespace Falak\Projects\Application\Listeners;

use Falak\Identity\Events\OrganizationCreated;
use Falak\Projects\Application\Actions\EnsureDefaultProject;

final class CreateDefaultProject
{
    public function __construct(private readonly EnsureDefaultProject $defaultProject) {}

    public function handle(OrganizationCreated $event): void
    {
        ($this->defaultProject)($event->organizationId, $event->ownerId);
    }
}
