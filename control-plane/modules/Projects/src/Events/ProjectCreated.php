<?php

namespace Falak\Projects\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ProjectCreated
{
    use Dispatchable;

    public function __construct(
        public string $projectId,
        public string $organizationId,
        public string $name,
        public bool $isDefault,
    ) {}
}
