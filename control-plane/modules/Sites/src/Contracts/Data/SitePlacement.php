<?php

namespace Kiln\Sites\Contracts\Data;

use Kiln\Sites\Events\SiteCreated;

/**
 * Where a new site should appear in the Projects model (opaque Projects ULIDs). Sites only carries it
 * through to {@see SiteCreated}; Projects places the site. Null ids = the organization's
 * default project / production environment; null coordinates = auto-layout; null name = the site name.
 */
final readonly class SitePlacement
{
    public function __construct(
        public ?string $projectId = null,
        public ?string $environmentId = null,
        public ?int $x = null,
        public ?int $y = null,
        /** Service name inside the environment (what variable references use). */
        public ?string $name = null,
    ) {}

    /**
     * @return array{project_id: ?string, environment_id: ?string, x: ?int, y: ?int, name: ?string}
     */
    public function toArray(): array
    {
        return ['project_id' => $this->projectId, 'environment_id' => $this->environmentId, 'x' => $this->x, 'y' => $this->y, 'name' => $this->name];
    }
}
