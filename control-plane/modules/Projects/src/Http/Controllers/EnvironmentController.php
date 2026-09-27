<?php

namespace Kiln\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Projects\Application\Actions\CreateEnvironment;
use Kiln\Projects\Application\Actions\DeleteEnvironment;
use Kiln\Projects\Application\Actions\UpdateEnvironment;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Projects\Http\Requests\ProjectRules;

final class EnvironmentController extends Controller
{
    use PresentsProjects;

    public function __construct(private readonly OrganizationAccess $access) {}

    /**
     * POST /projects/{project}/environments {name, from_environment_id?} — empty, or a duplicate of an
     * existing environment (site configs + variables, no servers; databases are not duplicated).
     */
    public function store(Request $request, Project $project, CreateEnvironment $create): JsonResponse|RedirectResponse
    {
        $this->authorize('manage', $project);
        $data = $request->validate(ProjectRules::environment(true), ProjectRules::messages());

        $from = isset($data['from_environment_id']) ? $this->resolveEnvironment($project, (string) $data['from_environment_id']) : null;

        if ($from !== null && $from->services()->exists()) {
            $this->access->authorize($request->user(), $project->organization_id, 'sites.create');
        }

        $environment = $create($project, (string) $data['name'], $request->user()?->getAuthIdentifier(), $from);

        if ($this->isInertia($request)) {
            return redirect("/projects/{$project->id}/{$environment->slug}")
                ->with($create->warnings === [] ? 'success' : 'warning', $create->warnings === [] ? "Environment {$environment->name} created." : implode(' ', $create->warnings));
        }

        return response()->json(['data' => $this->environment($environment, $environment->services()->count()), 'warnings' => $create->warnings], 201);
    }

    public function update(Request $request, Project $project, string $environment, UpdateEnvironment $update): JsonResponse|RedirectResponse
    {
        $this->authorize('manage', $project);
        $model = $this->resolveEnvironment($project, $environment);
        $data = $request->validate(ProjectRules::environment(false), ProjectRules::messages());

        $update($model, (string) $data['name']);

        return $this->isInertia($request)
            ? back()->with('success', 'Environment renamed.')
            : response()->json(['data' => $this->environment($model, $model->services()->count())]);
    }

    public function destroy(Request $request, Project $project, string $environment, DeleteEnvironment $delete): JsonResponse|RedirectResponse
    {
        $this->authorize('manage', $project);
        $delete($this->resolveEnvironment($project, $environment));

        return $this->isInertia($request)
            ? redirect("/projects/{$project->id}/settings")->with('success', 'Environment deleted.')
            : response()->json(null, 204);
    }
}
