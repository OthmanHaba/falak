<?php

namespace Kiln\Projects\Application\Actions;

use Kiln\Databases\Contracts\DatabaseDirectory;
use Kiln\Identity\Contracts\OrganizationDirectory;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Projects\Domain\Models\Service;
use Kiln\Sites\Contracts\SiteDirectory;

/**
 * Give every organization its default project and place every site / database that is in no
 * environment into the default production environment. Idempotent.
 */
final class BackfillProjects
{
    public function __construct(
        private readonly OrganizationDirectory $organizations,
        private readonly SiteDirectory $sites,
        private readonly DatabaseDirectory $databases,
        private readonly EnsureDefaultProject $defaultProject,
        private readonly LinkService $link,
    ) {}

    /**
     * @return array{organizations: int, projects: int, sites: int, databases: int}
     */
    public function __invoke(?string $organizationId = null): array
    {
        $counts = ['organizations' => 0, 'projects' => 0, 'sites' => 0, 'databases' => 0];

        foreach ($this->organizations->all() as $organization) {
            if ($organizationId !== null && $organization->id !== $organizationId) {
                continue;
            }

            $counts['organizations']++;
            $hadDefault = Project::query()->where('organization_id', $organization->id)->where('is_default', true)->exists();
            $environment = ($this->defaultProject)($organization->id);
            $counts['projects'] += $hadDefault ? 0 : 1;

            $placed = Service::query()->where('organization_id', $organization->id)->get(['kind', 'ref_id'])
                ->map(fn (Service $s) => $s->kind->value.':'.$s->ref_id)
                ->flip()
                ->all();

            foreach ($this->sites->forOrganization($organization->id) as $site) {
                if (! isset($placed['site:'.$site->id])) {
                    ($this->link)($environment, ServiceKind::Site, $site->id, $site->name);
                    $counts['sites']++;
                }
            }

            foreach ($this->databases->forOrganization($organization->id) as $database) {
                if (! isset($placed['database:'.$database->id])) {
                    ($this->link)($environment, ServiceKind::Database, $database->id, $database->name);
                    $counts['databases']++;
                }
            }
        }

        return $counts;
    }
}
