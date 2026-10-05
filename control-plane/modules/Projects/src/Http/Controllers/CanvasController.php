<?php

namespace Falak\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Application\Canvas\CanvasActivity;
use Falak\Projects\Application\Canvas\CanvasReadModel;
use Falak\Projects\Application\Canvas\FalakNavigation;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Domain\Models\Service;
use Falak\Sites\Contracts\SiteDirectory;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The project canvas page (with an optional service panel deep link) and its JSON read model.
 */
final class CanvasController extends Controller
{
    use PresentsProjects;

    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly CanvasReadModel $canvas,
    ) {}

    public function show(Request $request, Project $project, string $environment): Response
    {
        return $this->page($request, $project, $environment);
    }

    /**
     * /projects/{project}/{environment}/service/{kind}/{id}/{tab?} — the canvas with a service panel open.
     */
    public function panel(Request $request, Project $project, string $environment, string $kind, string $id, ?string $tab = null, ?string $item = null): Response
    {
        return $this->page($request, $project, $environment, [$kind, $id, $tab, $item]);
    }

    /**
     * GET /projects/{project}/{environment}/activity — the canvas Activity rail (recent deploys and services added).
     */
    public function activity(Project $project, string $environment, CanvasActivity $activity): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $activity->for($this->resolveEnvironment($project, $environment))]);
    }

    /**
     * GET /projects/{project}/{environment}/canvas — UI_DESIGN §9 `Canvas`.
     */
    public function canvas(Project $project, string $environment): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->canvas->for($this->resolveEnvironment($project, $environment)));
    }

    /**
     * GET /projects/{project}/{environment}/variables — what `${{ service.KEY }}` can point at in this environment
     * (UI_DESIGN §5.3): every service with the keys it exposes. Key names only, never values.
     */
    public function variables(Project $project, string $environment, SiteDirectory $sites, DatabaseDirectory $databases, DatabaseConnections $connections): JsonResponse
    {
        $this->authorize('view', $project);
        $model = $this->resolveEnvironment($project, $environment);
        $all = $model->services()->orderBy('name')->get();
        $engines = array_map(
            fn (DatabaseData $database) => $database->engine,
            $databases->findMany($all->where('kind', ServiceKind::Database)->pluck('ref_id')->values()->all()),
        );

        $services = $all->map(fn (Service $service) => [
            'id' => $service->id,
            'kind' => $service->kind->value,
            'ref_id' => $service->ref_id,
            'name' => $service->name,
            'handle' => Service::handle($service->name),
            'keys' => match ($service->kind) {
                ServiceKind::Site => array_keys($sites->environment($service->ref_id)->variables ?? []),
                ServiceKind::Database => $connections->keysFor($engines[$service->ref_id] ?? 'postgresql'),
            },
        ])->values();

        return response()->json(['data' => ['services' => $services]]);
    }

    /**
     * @param  ?array{0: string, 1: string, 2: ?string, 3: ?string}  $panel
     */
    private function page(Request $request, Project $project, string $environment, ?array $panel = null): Response
    {
        $this->authorize('view', $project);
        $model = $this->resolveEnvironment($project, $environment);

        FalakNavigation::remember($request, $model);

        return Inertia::render('Projects/Canvas', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'icon' => $project->icon,
                'is_default' => $project->is_default,
            ],
            'environment' => $this->environment($model),
            'canvas' => fn () => $this->canvas->for($model),
            'panel' => $panel !== null ? $this->panelFor($model, ...$panel) : null,
            'can' => $this->abilities($this->access, $request->user(), $project->organization_id),
        ]);
    }

    /**
     * @return array{kind: string, id: string, tab: ?string, item: ?string}
     */
    private function panelFor(Environment $environment, string $kind, string $id, ?string $tab, ?string $item): array
    {
        $kind = ServiceKind::tryFrom($kind) ?? throw new NotFoundHttpException;
        $id = strtolower($id);

        if (! $environment->services()->where('kind', $kind)->where('ref_id', $id)->exists()) {
            throw new NotFoundHttpException('Service not found in this environment.');
        }

        return ['kind' => $kind->value, 'id' => $id, 'tab' => $tab, 'item' => $tab !== null && $item !== null ? strtolower($item) : null];
    }
}
