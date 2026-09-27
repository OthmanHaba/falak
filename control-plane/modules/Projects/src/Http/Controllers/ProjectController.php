<?php

namespace Kiln\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Projects\Application\Actions\CreateProject;
use Kiln\Projects\Application\Actions\DeleteProject;
use Kiln\Projects\Application\Actions\UpdateProject;
use Kiln\Projects\Application\Canvas\ProjectSummaries;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Projects\Domain\Policies\ProjectPolicy;
use Kiln\Projects\Http\Requests\ProjectRules;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\SourceControl\Contracts\SourceControlGateway;

/**
 * Projects grid, settings page and project CRUD (JSON for fetch clients, redirects for Inertia visits).
 */
final class ProjectController extends Controller
{
    use PresentsProjects;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request, ProjectSummaries $summaries, ServerDirectory $servers, SourceControlGateway $sourceControl): Response|JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, ProjectPolicy::VIEW);

        $projects = $summaries->forOrganization($organizationId);

        if ($request->wantsJson() && ! $this->isInertia($request)) {
            return response()->json(['data' => $projects]);
        }

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            // First-run checklist (SetupChecklist on the grid).
            'setup' => fn () => [
                'gitConnected' => $sourceControl->connections($organizationId) !== [],
                'hasServer' => $servers->forOrganization($organizationId) !== [],
                'hasProject' => collect($projects)->contains(fn (array $project) => ! $project['is_default'] || $project['services_count'] > 0),
                'hasDeployment' => collect($projects)->contains(fn (array $project) => $project['last_deployment'] !== null),
            ],
            'can' => ['create' => $this->access->can($request->user(), $organizationId, ProjectPolicy::MANAGE)],
        ]);
    }

    public function store(Request $request, CreateProject $create): JsonResponse|RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, ProjectPolicy::MANAGE);

        $data = $request->validate(ProjectRules::project(true), ProjectRules::messages());
        $project = $create($organizationId, $request->user()?->getAuthIdentifier(), $data);

        if ($this->isInertia($request)) {
            return redirect("/projects/{$project->id}/{$project->production()?->slug}")->with('success', "Project {$project->name} created.");
        }

        return response()->json(['data' => $this->project($project)], 201);
    }

    /**
     * JSON project; a browser visit opens the production canvas.
     */
    public function show(Request $request, Project $project): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $project);

        if ($request->wantsJson() && ! $this->isInertia($request)) {
            return response()->json(['data' => $this->project($project)]);
        }

        $project->loadMissing('environments');

        return redirect("/projects/{$project->id}/{$project->production()?->slug}");
    }

    public function settings(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('Projects/Settings', [
            'project' => $this->project($project),
            'can' => $this->abilities($this->access, $request->user(), $project->organization_id),
        ]);
    }

    public function update(Request $request, Project $project, UpdateProject $update): JsonResponse|RedirectResponse
    {
        $this->authorize('manage', $project);

        $update($project, $request->validate(ProjectRules::project(false), ProjectRules::messages()));

        return $this->isInertia($request)
            ? back()->with('success', 'Project updated.')
            : response()->json(['data' => $this->project($project->refresh())]);
    }

    public function destroy(Request $request, Project $project, DeleteProject $delete): JsonResponse|RedirectResponse
    {
        $this->authorize('manage', $project);

        $request->validate(['confirm' => ['required', 'string', 'in:'.$project->name]], ['confirm.in' => 'Type the project name to confirm.']);

        $delete($project);

        return $this->isInertia($request)
            ? redirect('/projects')->with('success', "Project {$project->name} deleted.")
            : response()->json(null, 204);
    }
}
