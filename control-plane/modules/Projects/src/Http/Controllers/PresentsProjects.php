<?php

namespace Kiln\Projects\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Projects\Domain\Models\Environment;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Projects\Domain\Policies\ProjectPolicy;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait PresentsProjects
{
    /**
     * @return array{id: string, name: string, description: ?string, icon: ?string, is_default: bool, created_at: string, environments: list<array<string, mixed>>}
     */
    protected function project(Project $project): array
    {
        $project->loadMissing('environments');
        $counts = $project->services()->toBase()->selectRaw('environment_id, count(*) as aggregate')->groupBy('environment_id')->pluck('aggregate', 'environment_id');

        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'icon' => $project->icon,
            'is_default' => $project->is_default,
            'created_at' => $project->created_at->toIso8601String(),
            'environments' => $project->environments->map(fn (Environment $environment) => $this->environment($environment, (int) ($counts[$environment->id] ?? 0)))->values()->all(),
        ];
    }

    /**
     * @return array{id: string, project_id: string, name: string, slug: string, is_production: bool, forked_from_id: ?string, services_count?: int, created_at: string}
     */
    protected function environment(Environment $environment, ?int $servicesCount = null): array
    {
        return array_filter([
            'id' => $environment->id,
            'project_id' => $environment->project_id,
            'name' => $environment->name,
            'slug' => $environment->slug,
            'is_production' => $environment->is_production,
            'forked_from_id' => $environment->forked_from_id,
            'services_count' => $servicesCount,
            'created_at' => $environment->created_at->toIso8601String(),
        ], fn ($value, $key) => $key !== 'services_count' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * An environment of the project by slug or id.
     */
    protected function resolveEnvironment(Project $project, string $environment): Environment
    {
        $environment = strtolower($environment);

        return Environment::query()
            ->where('project_id', $project->id)
            ->where(fn ($q) => $q->where('slug', $environment)->orWhere('id', $environment))
            ->first() ?? throw new NotFoundHttpException('Environment not found.');
    }

    /**
     * @return array{view: bool, manage: bool, create_sites: bool, create_databases: bool}
     */
    protected function abilities(OrganizationAccess $access, ?Authenticatable $user, string $organizationId): array
    {
        return [
            'view' => $access->can($user, $organizationId, ProjectPolicy::VIEW),
            'manage' => $access->can($user, $organizationId, ProjectPolicy::MANAGE),
            'create_sites' => $access->can($user, $organizationId, ProjectPolicy::MANAGE) && $access->can($user, $organizationId, 'sites.create'),
            'create_databases' => $access->can($user, $organizationId, ProjectPolicy::MANAGE) && $access->can($user, $organizationId, 'databases.manage'),
        ];
    }

    /** JSON for API clients; Inertia visits (router.post …) get a redirect instead. */
    protected function isInertia(Request $request): bool
    {
        return $request->header('X-Inertia') !== null;
    }
}
