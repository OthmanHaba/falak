<?php

namespace Kiln\Templates\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Projects\Contracts\Data\EnvironmentData;
use Kiln\Projects\Contracts\ProjectDirectory;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Sites\Contracts\Data\DomainChoice;
use Kiln\Templates\Application\Actions\DeployTemplate;
use Kiln\Templates\Application\Catalog\TemplateRepository;
use Kiln\Templates\Domain\TemplateSource;
use Kiln\Templates\TemplatesServiceProvider;

/**
 * POST /projects/{project}/{environment}/templates/{slug}/deploy — the Deploy button of the configure form.
 */
final class TemplateDeployController extends Controller
{
    public function __invoke(
        Request $request,
        string $project,
        string $environment,
        string $slug,
        CurrentOrganization $organization,
        OrganizationAccess $access,
        ProjectDirectory $projects,
        TemplateRepository $templates,
        DeployTemplate $deploy,
    ): JsonResponse {
        $organizationId = $organization->requireId();
        $user = $request->user();
        $env = $this->environment($projects, $organizationId, $project, $environment);

        $access->authorize($user, $organizationId, TemplatesServiceProvider::VIEW);
        $access->authorize($user, $organizationId, 'projects.manage');
        $access->authorize($user, $organizationId, 'sites.create');

        $data = $request->validate([
            'source' => ['nullable', Rule::enum(TemplateSource::class)],
            'name' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/'],
            'inputs' => ['nullable', 'array', 'max:200'],
            'domains' => ['nullable', 'array', 'max:50'],
            // service => a domain name, or {type: generated|test|custom, name?}; missing: the organization's default.
            'domains.*' => ['nullable', DomainChoice::rule()],
            'server_ids' => ['required', 'array', 'min:1', 'max:50'],
            'server_ids.*' => ['string', 'size:26'],
            'position' => ['nullable', 'array'],
            'position.x' => ['nullable', 'integer', 'between:-1000000,1000000'],
            'position.y' => ['nullable', 'integer', 'between:-1000000,1000000'],
        ], ['name.regex' => 'Use letters, numbers, spaces, dots, dashes and underscores.']);

        $template = $templates->find($organizationId, $slug, isset($data['source']) ? TemplateSource::from($data['source']) : null) ?? abort(404, 'Template not found.');
        $canDeploy = $access->can($user, $organizationId, 'deployments.create');

        $result = $deploy(
            $env,
            $user?->getAuthIdentifier(),
            $template,
            (array) ($data['inputs'] ?? []),
            (array) ($data['domains'] ?? []),
            array_values(array_map(fn ($id) => strtolower((string) $id), $data['server_ids'])),
            $data['name'] ?? null,
            isset($data['position']['x']) ? (int) $data['position']['x'] : null,
            isset($data['position']['y']) ? (int) $data['position']['y'] : null,
            deploy: $canDeploy,
        );

        $warnings = $result->warnings;
        if (! $canDeploy) {
            $warnings[] = 'You cannot start deployments; ask a developer to deploy it.';
        }

        $service = $projects->projectOf(ServiceKind::Site, $result->site->id);

        return response()->json([
            'data' => [
                'site_id' => $result->site->id,
                'name' => $result->site->name,
                'service_id' => $service?->id,
                'deployment_id' => $result->deploymentId,
                'domains' => $result->domains,
                'panel_url' => $projects->serviceUrl(ServiceKind::Site, $result->site->id, 'deployments'),
            ],
            'warnings' => $warnings,
        ], 201);
    }

    private function environment(ProjectDirectory $projects, string $organizationId, string $projectId, string $environment): EnvironmentData
    {
        $project = $projects->find(strtolower($projectId));
        abort_if($project === null || $project->organizationId !== $organizationId, 404, 'Project not found.');

        foreach ($projects->environments($project->id) as $candidate) {
            if ($candidate->slug === strtolower($environment) || $candidate->id === strtolower($environment)) {
                return $candidate;
            }
        }

        abort(404, 'Environment not found.');
    }
}
