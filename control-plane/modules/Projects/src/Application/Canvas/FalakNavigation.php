<?php

namespace Falak\Projects\Application\Canvas;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Domain\Policies\ProjectPolicy;
use Illuminate\Http\Request;

/**
 * The `falak` Inertia prop shared with every authenticated page (UI_DESIGN §9 `FalakShared`): the current
 * organization's projects with their environments, and the project / environment in view (from the URL,
 * else the last canvas visited in this organization).
 */
final class FalakNavigation
{
    public const SESSION_KEY = 'projects.last_visited';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    /**
     * @return array{projects: list<array{id: string, name: string, icon: ?string, environments: list<array{id: string, name: string, slug: string, is_production: bool}>}>, current: array{project_id: ?string, environment_id: ?string}}
     */
    public function for(Request $request): array
    {
        $empty = ['projects' => [], 'current' => ['project_id' => null, 'environment_id' => null]];
        $organizationId = $this->organization->id();

        if ($organizationId === null || ! $this->access->can($request->user(), $organizationId, ProjectPolicy::VIEW)) {
            return $empty;
        }

        $projects = Project::query()->where('organization_id', $organizationId)->with('environments')->orderByDesc('is_default')->orderBy('name')->get();

        return [
            'projects' => $projects->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'icon' => $project->icon,
                'environments' => $project->environments->map(fn (Environment $environment) => [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'slug' => $environment->slug,
                    'is_production' => $environment->is_production,
                ])->values()->all(),
            ])->values()->all(),
            'current' => $this->current($request, $organizationId, $projects->keyBy('id')->all()),
        ];
    }

    /**
     * Remember the canvas the user is looking at (per organization).
     */
    public static function remember(Request $request, Environment $environment): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY.'.'.$environment->organization_id, [
                'project_id' => $environment->project_id,
                'environment_id' => $environment->id,
            ]);
        }
    }

    /**
     * @param  array<string, Project>  $projects
     * @return array{project_id: ?string, environment_id: ?string}
     */
    private function current(Request $request, string $organizationId, array $projects): array
    {
        $routeProject = $request->route('project');
        $projectId = is_string($routeProject) ? strtolower($routeProject) : ($routeProject instanceof Project ? $routeProject->id : null);

        if ($projectId !== null && isset($projects[$projectId])) {
            $routeEnvironment = $request->route('environment');
            $environment = is_string($routeEnvironment)
                ? $projects[$projectId]->environments->first(fn (Environment $e) => $e->slug === strtolower($routeEnvironment) || $e->id === strtolower($routeEnvironment))
                : null;

            return ['project_id' => $projectId, 'environment_id' => $environment?->id ?? $projects[$projectId]->production()?->id];
        }

        $last = $request->hasSession() ? $request->session()->get(self::SESSION_KEY.'.'.$organizationId) : null;

        if (is_array($last) && isset($projects[$last['project_id'] ?? ''])) {
            $project = $projects[$last['project_id']];
            $environment = $project->environments->firstWhere('id', $last['environment_id'] ?? null) ?? $project->production();

            return ['project_id' => $project->id, 'environment_id' => $environment?->id];
        }

        return ['project_id' => null, 'environment_id' => null];
    }
}
