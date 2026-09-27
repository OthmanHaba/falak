<?php

namespace Kiln\Projects\Application\Actions;

use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Projects\Domain\Models\Environment;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Projects\Domain\Models\Service;

/**
 * Link a new site / database where its creator asked (environment, else a project's production
 * environment), falling back to the organization's default project.
 */
final class PlaceService
{
    public function __construct(
        private readonly LinkService $link,
        private readonly EnsureDefaultProject $defaultProject,
    ) {}

    public function __invoke(string $organizationId, ServiceKind $kind, string $refId, string $name, ?string $projectId = null, ?string $environmentId = null, ?int $x = null, ?int $y = null): Service
    {
        $environment = $this->target($organizationId, $projectId, $environmentId) ?? ($this->defaultProject)($organizationId);

        return ($this->link)($environment, $kind, $refId, $name, $x, $y);
    }

    private function target(string $organizationId, ?string $projectId, ?string $environmentId): ?Environment
    {
        if ($environmentId !== null) {
            $environment = Environment::query()->where('organization_id', $organizationId)->find(strtolower($environmentId));

            if ($environment !== null && ($projectId === null || $environment->project_id === strtolower($projectId))) {
                return $environment;
            }
        }

        if ($projectId !== null) {
            return Project::query()->where('organization_id', $organizationId)->with('environments')->find(strtolower($projectId))?->production();
        }

        return null;
    }
}
