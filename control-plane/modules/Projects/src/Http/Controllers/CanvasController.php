<?php

namespace Kiln\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Projects\Application\Canvas\CanvasReadModel;
use Kiln\Projects\Application\Canvas\KilnNavigation;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Projects\Domain\Models\Environment;
use Kiln\Projects\Domain\Models\Project;
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
    public function panel(Request $request, Project $project, string $environment, string $kind, string $id, ?string $tab = null): Response
    {
        return $this->page($request, $project, $environment, [$kind, $id, $tab]);
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
     * @param  ?array{0: string, 1: string, 2: ?string}  $panel
     */
    private function page(Request $request, Project $project, string $environment, ?array $panel = null): Response
    {
        $this->authorize('view', $project);
        $model = $this->resolveEnvironment($project, $environment);

        KilnNavigation::remember($request, $model);

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
     * @return array{kind: string, id: string, tab: ?string}
     */
    private function panelFor(Environment $environment, string $kind, string $id, ?string $tab): array
    {
        $kind = ServiceKind::tryFrom($kind) ?? throw new NotFoundHttpException;
        $id = strtolower($id);

        if (! $environment->services()->where('kind', $kind)->where('ref_id', $id)->exists()) {
            throw new NotFoundHttpException('Service not found in this environment.');
        }

        return ['kind' => $kind->value, 'id' => $id, 'tab' => $tab];
    }
}
