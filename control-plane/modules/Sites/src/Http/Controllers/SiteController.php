<?php

namespace Kiln\Sites\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Projects\Contracts\ProjectDirectory;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Application\Actions\CreateSite;
use Kiln\Sites\Application\Actions\DeleteSite;
use Kiln\Sites\Contracts\DeployScript;
use Kiln\Sites\Contracts\SiteDomains;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteCommand;
use Kiln\Sites\Http\Requests\StoreSiteRequest;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;
use Kiln\SourceControl\Contracts\SourceControlGateway;

final class SiteController extends Controller
{
    use PresentsSites;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
    ) {}

    public function index(Request $request, SiteDomains $domains): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'sites.view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'runtime' => ['nullable', Rule::enum(SiteRuntime::class)],
            'server' => ['nullable', 'string', 'size:26'],
        ]);

        $sites = Site::query()
            ->with('targets')
            ->where('organization_id', $organizationId)
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('repository', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
            ->when($filters['runtime'] ?? null, fn ($q, $runtime) => $q->where('runtime', $runtime))
            ->when($filters['server'] ?? null, fn ($q, $server) => $q->whereHas('targets', fn ($q) => $q->where('server_id', $server)))
            ->orderBy('name')
            ->get();

        $servers = $this->serversById($this->servers, $sites->flatMap(fn (Site $site) => $site->serverIds())->all());
        $primary = $domains->primaryDomains($sites->pluck('id')->all());

        return Inertia::render('Sites/Index', [
            'sites' => $sites->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'slug' => $site->slug,
                'runtime' => $site->runtime->value,
                'runtime_label' => $site->runtime->label(),
                'framework' => $site->framework->value,
                'framework_label' => $site->framework->label(),
                'repository' => $site->repository,
                'branch' => $site->branch,
                'primary_domain' => $primary[$site->id] ?? null,
                'test_domain' => $site->testDomain(),
                'php_version' => $site->php_version,
                'status' => $this->status($site),
                'servers' => array_map(fn (array $target) => ['id' => $target['server_id'], 'name' => $target['server_name'], 'role' => $target['role']], $this->targets($site, $servers)),
                'created_at' => $site->created_at->toIso8601String(),
            ])->values(),
            'filters' => array_filter($filters),
            'runtimes' => array_map(fn (SiteRuntime $runtime) => ['value' => $runtime->value, 'label' => $runtime->label()], SiteRuntime::cases()),
            'serverOptions' => array_values(array_map(fn ($server) => ['id' => $server->id, 'name' => $server->name], array_filter($this->servers->forOrganization($organizationId), fn ($server) => $server->type->hostsSites()))),
            'can' => ['create' => $this->access->can($request->user(), $organizationId, 'sites.create')],
        ]);
    }

    /**
     * The create form; JSON (options only) for the canvas Create picker.
     */
    public function create(Request $request, AgentDirectory $agents, SourceControlGateway $sourceControl): Response|JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'sites.create');

        if ($request->wantsJson() && $request->header('X-Inertia') === null) {
            return response()->json(['data' => [
                'options' => $this->options($organizationId, $this->servers, $agents, $sourceControl),
                'can_manage_source_control' => $this->access->can($request->user(), $organizationId, 'source_control.manage'),
            ]]);
        }

        return Inertia::render('Sites/Create', [
            'options' => $this->options($organizationId, $this->servers, $agents, $sourceControl),
            'canManageSourceControl' => $this->access->can($request->user(), $organizationId, 'source_control.manage'),
        ]);
    }

    public function store(StoreSiteRequest $request, CreateSite $create): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'sites.create');

        $site = $create($organizationId, $request->user()?->getAuthIdentifier(), $request->siteData(), $request->placement());

        return to_route('sites.show', $site)->with('sites.warnings', $create->warnings);
    }

    /**
     * Sites placed in a project open in their canvas panel (UI_DESIGN §3 legacy redirects).
     */
    public function show(Request $request, Site $site, SourceControlGateway $sourceControl, ProjectDirectory $projects): Response|RedirectResponse
    {
        $this->authorize('view', $site);

        if ($panel = $projects->serviceUrl(ServiceKind::Site, $site->id)) {
            return redirect($panel);
        }

        $site->load('targets');

        $servers = $this->serversById($this->servers, $site->serverIds());
        $deployKey = null;
        $connection = null;

        try {
            $deployKey = $site->deploy_key_id ? $sourceControl->deployKey($site->deploy_key_id) : null;
            $connection = $site->source_connection_id ? $sourceControl->connection($site->source_connection_id) : null;
        } catch (SourceControlException) {
            // Shown as "unavailable" in the UI.
        }

        $environment = $site->environmentVersions()->first(['id', 'site_id', 'version', 'created_at']);

        return Inertia::render('Sites/Show', [
            'site' => $this->header($site),
            'details' => [
                'status' => $this->status($site),
                'runtime' => $site->runtime->value,
                'runtime_label' => $site->runtime->label(),
                'build_mode' => $site->build_mode->label(),
                'framework' => $site->framework->value,
                'is_laravel' => $site->framework->isLaravel(),
                'php_version' => $site->php_version,
                'node_version' => $site->node_version,
                'web_directory' => $site->web_directory,
                'root_path' => $site->rootPath(),
                'document_root' => $site->toData()->documentRoot(),
                'unix_user' => $site->unix_user,
                'isolated' => $site->isolated,
                'app_port' => $site->app_port,
                'docker_image' => $site->docker_image,
                'dockerfile' => $site->dockerfile,
                'compose_file' => $site->compose_file,
                'health_check_path' => $site->health_check_path,
                'push_to_deploy' => $site->push_to_deploy,
                'connection' => $connection ? ['name' => $connection->name, 'provider' => $connection->provider->value, 'provider_label' => $connection->provider->label()] : null,
                'deploy_key' => $deployKey ? ['public_key' => $deployKey->publicKey, 'fingerprint' => $deployKey->fingerprint, 'installed' => $deployKey->installed, 'install_error' => $deployKey->installError] : null,
                'laravel' => $site->laravel->toArray(),
                'shared_paths' => array_map(fn ($path) => $path->toArray(), $site->shared_paths),
                'environment_version' => $environment?->version,
                'deploy_macros' => DeployScript::macrosIn($site->deploy_script),
                'created_at' => $site->created_at->toIso8601String(),
            ],
            'targets' => $this->targets($site, $servers),
            'recentCommands' => $site->commands()->limit(5)->get()->map(fn (SiteCommand $command) => [
                'id' => $command->id,
                'command' => $command->command,
                'server_name' => $servers[$command->server_id]->name ?? 'deleted server',
                'status' => $command->status,
                'created_at' => $command->created_at->toIso8601String(),
            ])->values(),
            'warnings' => array_values((array) $request->session()->get('sites.warnings', [])),
            'can' => [
                'update' => $request->user()?->can('update', $site) ?? false,
                'runCommands' => $request->user()?->can('runCommands', $site) ?? false,
            ],
        ]);
    }

    public function destroy(Request $request, Site $site, DeleteSite $delete): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $site);

        $request->validate(['name' => ['required', 'string', Rule::in([$site->name])]], ['name.in' => 'Type the site name to confirm.']);

        $delete($site);

        return $request->wantsJson() && $request->header('X-Inertia') === null ? response()->json(null, 204) : to_route('sites.index');
    }

    public function search(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();

        if (! $this->access->can($request->user(), $organizationId, 'sites.view')) {
            return response()->json(['data' => []]);
        }

        $query = (string) $request->string('q');

        return response()->json([
            'data' => Site::query()
                ->where('organization_id', $organizationId)
                ->when($query !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$query}%")->orWhere('slug', 'like', "%{$query}%")->orWhere('repository', 'like', "%{$query}%")))
                ->orderBy('name')
                ->limit(10)
                ->get(['id', 'name', 'slug', 'runtime', 'repository'])
                ->map(fn (Site $site) => ['id' => $site->id, 'name' => $site->name, 'slug' => $site->slug, 'runtime' => $site->runtime->value, 'repository' => $site->repository]),
        ]);
    }
}
