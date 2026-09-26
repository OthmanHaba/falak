<?php

namespace Kiln\Sites\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Http\Controllers\PresentsSites;

/**
 * Public API (Sanctum tokens; abilities are permission names). Never exposes environment values.
 */
final class SiteApiController extends Controller
{
    use PresentsSites;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'sites.view');

        $sites = Site::query()->with('targets')->where('organization_id', $organizationId)->orderBy('name')->get();

        return response()->json(['data' => $sites->map(fn (Site $site) => $this->resource($site))->values()]);
    }

    public function show(Site $site): JsonResponse
    {
        $this->authorize('view', $site);
        $site->load('targets');

        return response()->json(['data' => [
            ...$this->resource($site),
            'deploy_script' => $site->deploy_script,
            'shared_paths' => array_map(fn ($path) => $path->toArray(), $site->shared_paths),
            'laravel' => $site->laravel->toArray(),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Site $site): array
    {
        $servers = $this->serversById($this->servers, $site->serverIds());

        return [
            'id' => $site->id,
            'name' => $site->name,
            'slug' => $site->slug,
            'status' => $this->status($site),
            'framework' => $site->framework->value,
            'runtime' => $site->runtime->value,
            'build_mode' => $site->build_mode->value,
            'php_version' => $site->php_version,
            'node_version' => $site->node_version,
            'repository' => $site->repository,
            'branch' => $site->branch,
            'push_to_deploy' => $site->push_to_deploy,
            'web_directory' => $site->web_directory,
            'root_path' => $site->rootPath(),
            'app_port' => $site->app_port,
            'test_domain' => $site->testDomain(),
            'targets' => $this->targets($site, $servers),
            'created_at' => $site->created_at->toIso8601String(),
        ];
    }
}
