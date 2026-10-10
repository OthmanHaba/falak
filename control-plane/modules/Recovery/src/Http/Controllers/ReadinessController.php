<?php

namespace Falak\Recovery\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Contracts\Data\ProjectData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Recovery\Application\ReadinessScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DR readiness per project: the score and the gaps, each linking to its fix.
 */
final class ReadinessController extends Controller
{
    public const PERMISSION = 'recovery.view';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ProjectDirectory $projects,
        private readonly ReadinessScore $readiness,
    ) {}

    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, self::PERMISSION);

        return Inertia::render('Recovery/Readiness', [
            'projects' => array_map(fn (ProjectData $project) => $this->readiness->forProject($project), $this->projects->forOrganization($organizationId)),
        ]);
    }

    /** GET /recovery/readiness/{project} */
    public function show(Request $request, string $project): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $data = $this->projects->find(strtolower($project));
        abort_if($data === null || $data->organizationId !== $organizationId, 404);
        $this->access->authorize($request->user(), $organizationId, self::PERMISSION);

        return response()->json(['data' => $this->readiness->forProject($data)]);
    }
}
