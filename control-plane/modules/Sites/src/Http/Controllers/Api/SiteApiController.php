<?php

namespace Falak\Sites\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Application\Actions\CreateSite;
use Falak\Sites\Application\Actions\SaveEnvironment;
use Falak\Sites\Application\Actions\UpdateLaravelSettings;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\OctaneServer;
use Falak\Sites\Contracts\SiteDomains;
use Falak\Sites\Contracts\SiteResourceExtension;
use Falak\Sites\Domain\Dotenv;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Http\Controllers\PresentsSites;
use Falak\Sites\Http\Requests\StoreSiteRequest;

/**
 * Public API (Sanctum tokens; abilities are permission names). Sites are addressed by id or slug.
 * Environment values are only returned by the env endpoint, which requires sites.env.view.
 */
final class SiteApiController extends Controller
{
    use PresentsSites;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
        private readonly SiteDomains $domains,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'sites.view');

        $sites = Site::query()->with('targets')->where('organization_id', $organizationId)->orderBy('name')->get();

        return response()->json(['data' => $this->resources($sites)]);
    }

    public function store(StoreSiteRequest $request, CreateSite $create): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'sites.create');

        $site = $create($organizationId, $request->user()?->getAuthIdentifier(), $request->siteData(), $request->placement());

        $body = $this->show($request, $site->id)->getData(true);
        $body['warnings'] = array_values($create->warnings);

        return response()->json($body, 201);
    }

    public function show(Request $request, string $site): JsonResponse
    {
        $model = $this->resolve($request, $site, 'sites.view');

        return response()->json(['data' => [
            ...$this->resources(collect([$model]))[0],
            'deploy_script' => $model->deploy_script,
            'shared_paths' => array_map(fn ($path) => $path->toArray(), $model->shared_paths),
            'laravel' => $model->laravel->toArray(),
        ]]);
    }

    /**
     * GET /api/v1/sites/{site}/env → {data: {content}} (dotenv of the latest version).
     */
    public function env(Request $request, string $site, AuditLog $audit): JsonResponse
    {
        $model = $this->resolve($request, $site, 'sites.env.view');
        $environment = $model->latestEnvironment;
        $audit->record('site.environment_revealed', 'site', $model->id, ['via' => 'api', 'version' => $environment?->version], $model->organization_id);

        return response()->json(['data' => [
            'content' => $environment?->toData()->toDotenv() ?? '',
            'version' => $environment?->version,
        ]]);
    }

    /**
     * PUT /api/v1/sites/{site}/env {content} → new environment version (deploy-script exposure kept).
     */
    public function updateEnv(Request $request, string $site, SaveEnvironment $save): JsonResponse
    {
        $model = $this->resolve($request, $site, 'sites.env.manage');
        $data = $request->validate(['content' => ['present', 'nullable', 'string', 'max:262144']]);

        try {
            $variables = Dotenv::parse((string) ($data['content'] ?? ''));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['content' => $e->getMessage()]);
        }

        /** @var ?EnvironmentVersion $current */
        $current = $model->latestEnvironment;
        $version = $save($model, $variables, $current->exposed ?? [], (string) $request->user()?->getAuthIdentifier());

        return response()->json(['data' => [
            'version' => $version->version ?? $current?->version,
            'changed' => $version !== null,
            'keys' => array_keys($variables),
        ]]);
    }

    /**
     * PUT /api/v1/sites/{site}/laravel {scheduler, horizon, octane, maintenance, octane_server?} → the site's Laravel
     * settings (Octane server + allocated port included).
     */
    public function updateLaravel(Request $request, string $site, UpdateLaravelSettings $update): JsonResponse
    {
        $model = $this->resolve($request, $site, 'sites.manage');
        $data = $request->validate([
            'scheduler' => ['sometimes', 'boolean'],
            'horizon' => ['sometimes', 'boolean'],
            'octane' => ['sometimes', 'boolean'],
            'maintenance' => ['sometimes', 'boolean'],
            'octane_server' => ['nullable', Rule::enum(OctaneServer::class)],
        ]);

        $update($model, LaravelSettings::fromArray([...$model->laravel->toArray(), ...$data]), (string) $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $model->refresh()->laravel->toArray()]);
    }

    private function resolve(Request $request, string $idOrSlug, string $permission): Site
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'sites.view');

        $site = Site::query()->with('targets')->where('organization_id', $organizationId)
            ->where(fn ($q) => $q->where('id', strtolower($idOrSlug))->orWhere('slug', strtolower($idOrSlug)))
            ->first();

        abort_if($site === null, 404, 'Site not found.');
        $this->access->authorize($request->user(), $organizationId, $permission);

        return $site;
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return list<array<string, mixed>>
     */
    private function resources(Collection $sites): array
    {
        $ids = $sites->pluck('id')->all();
        $domains = $ids === [] ? [] : $this->domains->primaryDomains($ids);
        $extra = [];

        foreach (app()->tagged(SiteResourceExtension::TAG) as $extension) {
            /** @var SiteResourceExtension $extension */
            foreach ($extension->fields($ids) as $siteId => $fields) {
                $extra[$siteId] = [...($extra[$siteId] ?? []), ...$fields];
            }
        }

        return $sites->map(function (Site $site) use ($domains, $extra) {
            $servers = $this->serversById($this->servers, $site->serverIds());
            $domain = $domains[$site->id] ?? $site->testDomain();

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
                'root_directory' => $site->root_directory,
                'push_to_deploy' => $site->push_to_deploy,
                'domain' => $domain,
                'url' => $domain ? "https://{$domain}" : null,
                'web_directory' => $site->web_directory,
                'root_path' => $site->rootPath(),
                'app_port' => $site->app_port,
                'container_port' => $site->container_port,
                'test_domain' => $site->testDomain(),
                'server_ids' => $site->serverIds(),
                'targets' => $this->targets($site, $servers),
                'compose' => ($compose = $site->composeConfig()) !== null ? [
                    'source' => $compose->source->value,
                    'file' => $compose->file,
                    'version' => $compose->version,
                    'public_services' => array_map(fn ($public) => $public->toArray(), $compose->publicServices),
                    'template' => $compose->template,
                ] : null,
                'created_at' => $site->created_at->toIso8601String(),
                ...($extra[$site->id] ?? []),
            ];
        })->values()->all();
    }
}
