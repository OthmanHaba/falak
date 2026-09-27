<?php

namespace Kiln\Projects\Application\Canvas;

use DateTimeInterface;
use Kiln\Databases\Contracts\DatabaseDirectory;
use Kiln\Deployments\Contracts\Data\DeploymentSummary;
use Kiln\Deployments\Contracts\DeploymentDirectory;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Projects\Domain\Models\Environment;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Projects\Domain\Models\Service;
use Kiln\Sites\Contracts\SiteDirectory;

/**
 * Cards of the Projects grid: environments, service icons, latest deployment and an overall status.
 */
final class ProjectSummaries
{
    /** Service icons shown per card. */
    public const ICONS = 8;

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly DatabaseDirectory $databases,
        private readonly DeploymentDirectory $deployments,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forOrganization(string $organizationId): array
    {
        $projects = Project::query()->where('organization_id', $organizationId)->with('environments')->orderByDesc('is_default')->orderBy('name')->get();
        $services = Service::query()->where('organization_id', $organizationId)->orderBy('created_at')->orderBy('id')->get()->groupBy('project_id');

        $sites = [];

        foreach ($this->sites->forOrganization($organizationId) as $site) {
            $sites[$site->id] = $site;
        }

        $databases = [];

        foreach ($this->databases->forOrganization($organizationId) as $database) {
            $databases[$database->id] = $database;
        }

        $deployments = $this->deployments->currentForSites(array_keys($sites));

        return $projects->map(function (Project $project) use ($services, $sites, $databases, $deployments) {
            /** @var list<Service> $projectServices */
            $projectServices = ($services[$project->id] ?? collect())->all();
            $projectDeployments = array_values(array_filter(array_map(
                fn (Service $s) => $s->kind === ServiceKind::Site ? ($deployments[$s->ref_id] ?? null) : null,
                $projectServices,
            )));
            $latest = collect($projectDeployments)->sortByDesc(fn (DeploymentSummary $d) => $d->createdAt->getTimestamp())->first();

            return [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'icon' => $project->icon,
                'is_default' => $project->is_default,
                'environments' => $project->environments->map(fn (Environment $e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'slug' => $e->slug,
                    'is_production' => $e->is_production,
                ])->values()->all(),
                'services_count' => count($projectServices),
                'services' => array_values(array_slice(array_filter(array_map(fn (Service $s) => match (true) {
                    $s->kind === ServiceKind::Site && isset($sites[$s->ref_id]) => ['kind' => 'site', 'name' => $s->name, 'icon' => CanvasReadModel::siteIcon($sites[$s->ref_id])],
                    $s->kind === ServiceKind::Database && isset($databases[$s->ref_id]) => ['kind' => 'database', 'name' => $s->name, 'icon' => $databases[$s->ref_id]->engine],
                    default => null,
                }, $projectServices)), 0, self::ICONS)),
                'last_deployment' => $latest !== null ? [
                    'id' => $latest->id,
                    'site_id' => $latest->siteId,
                    'status' => $latest->status,
                    'finished_at' => $latest->finishedAt?->format(DateTimeInterface::ATOM),
                    'created_at' => $latest->createdAt->format(DateTimeInterface::ATOM),
                ] : null,
                'status' => $this->status($projectDeployments),
                'created_at' => $project->created_at->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @param  list<DeploymentSummary>  $deployments
     */
    private function status(array $deployments): string
    {
        $statuses = array_map(fn (DeploymentSummary $d) => $d->status, $deployments);

        return match (true) {
            array_intersect(['building', 'deploying'], $statuses) !== [] => 'deploying',
            in_array('failed', $statuses, true) => 'failed',
            in_array('succeeded', $statuses, true) => 'active',
            default => 'inactive',
        };
    }
}
