<?php

namespace Kiln\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Projects\Application\Actions\CreateService;
use Kiln\Projects\Application\Actions\MoveService;
use Kiln\Projects\Application\Canvas\CanvasReadModel;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Projects\Domain\Models\Service;
use Kiln\Projects\Http\Requests\ProjectRules;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Canvas services: the Create picker and card positions.
 */
final class ServiceController extends Controller
{
    use PresentsProjects;

    public function __construct(private readonly OrganizationAccess $access) {}

    /**
     * POST /projects/{project}/{environment}/services
     * {kind: site, …site fields, x?, y?} | {kind: database, engine, server_id, name, x?, y?}
     * → 201 {data: CanvasService, warnings}
     */
    public function store(Request $request, Project $project, string $environment, CreateService $create, CanvasReadModel $canvas): JsonResponse
    {
        $this->authorize('manage', $project);
        $model = $this->resolveEnvironment($project, $environment);

        $base = $request->validate([
            'kind' => ['required', Rule::enum(ServiceKind::class)],
            'x' => ['nullable', ...ProjectRules::COORDINATE],
            'y' => ['nullable', ...ProjectRules::COORDINATE],
        ]);
        $kind = ServiceKind::from($base['kind']);

        if ($kind === ServiceKind::Site) {
            $this->access->authorize($request->user(), $project->organization_id, 'sites.create');
            $data = $request->except(['kind', 'x', 'y', 'project_id', 'environment_id']);
        } else {
            $this->access->authorize($request->user(), $project->organization_id, 'databases.manage');
            $data = $request->validate([
                'engine' => ['required', 'string', Rule::in(['postgresql', 'mysql', 'mariadb', 'redis'])],
                'server_id' => ['required', 'string', 'size:26'],
                'name' => ['required', 'string', 'max:63'],
            ]);
        }

        $x = isset($base['x']) ? (int) $base['x'] : null;
        $y = isset($base['y']) ? (int) $base['y'] : null;
        $service = $create($model, $request->user()?->getAuthIdentifier(), $kind, $data, $x, $y);

        $card = collect($canvas->for($model)['services'])->firstWhere('id', $service->id);

        return response()->json(['data' => $card, 'warnings' => $create->warnings], 201);
    }

    /**
     * PATCH /projects/{project}/{environment}/services/{service}/position {x, y}
     */
    public function position(Request $request, Project $project, string $environment, string $service, MoveService $move): JsonResponse
    {
        $this->authorize('manage', $project);
        $model = $this->resolveEnvironment($project, $environment);

        $data = $request->validate([
            'x' => ['required', ...ProjectRules::COORDINATE],
            'y' => ['required', ...ProjectRules::COORDINATE],
        ]);

        $record = Service::query()->where('environment_id', $model->id)->find(strtolower($service)) ?? throw new NotFoundHttpException('Service not found.');
        $move($record, (int) $data['x'], (int) $data['y']);

        return response()->json(['data' => ['id' => $record->id, 'position' => ['x' => $record->x, 'y' => $record->y]]]);
    }
}
