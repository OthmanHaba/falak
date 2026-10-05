<?php

namespace Falak\Edge\Http\Controllers;

use Falak\Edge\Application\ComposeServiceDomains;
use Falak\Edge\Application\PathMounts;
use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Domain\Models\Mount;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A function's Settings → Paths: the sites whose paths it serves (JSON for the service panel).
 */
final class MountController extends Controller
{
    use ResolvesSite;

    public function index(Request $request, string $site, SiteDirectory $sites, EdgeRoutes $routes): JsonResponse
    {
        $function = $this->function($request, $site);
        $hosts = array_values(array_filter($sites->forOrganization($function->organizationId), fn (SiteData $s) => ! $s->runtime->isFunction()));
        $byId = collect($hosts)->keyBy('id');

        return response()->json(['data' => [
            'mounts' => Mount::query()->where('function_site_id', $function->id)->orderBy('created_at')->get()->map(fn (Mount $m) => [
                'id' => $m->id,
                'site_id' => $m->site_id,
                'site_name' => $byId->get($m->site_id)?->name,
                'service' => $m->compose_service,
                'path_prefix' => $m->path_prefix,
                'strip_prefix' => $m->strip_prefix,
                'urls' => array_map(fn ($domain) => "https://{$domain->name}{$m->path_prefix}", $routes->domainsFor($m->site_id, $m->compose_service)),
            ])->values(),
            // Compose sites list their public services: a path may be served on one service's domains only.
            'sites' => array_map(fn (SiteData $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'domains' => array_map(fn ($d) => $d->name, $routes->domainsFor($s->id)),
                'services' => array_map(fn (array $o) => $o['service'], ComposeServiceDomains::options($s)),
            ], $hosts),
            'can' => ['manage' => app(OrganizationAccess::class)->can($request->user(), $function->organizationId, 'edge.manage')],
        ]]);
    }

    public function store(Request $request, string $site, PathMounts $mounts): JsonResponse
    {
        $function = $this->function($request, $site, 'edge.manage');
        $data = $request->validate([
            'site_id' => ['required', 'string', 'size:26'],
            'path_prefix' => ['required', 'string', 'max:200'],
            'strip_prefix' => ['sometimes', 'boolean'],
            'service' => ['nullable', 'string', 'max:63'],
        ]);
        $mount = $mounts->create($function, $data['site_id'], $data['path_prefix'], (bool) ($data['strip_prefix'] ?? false), $data['service'] ?? null);

        return response()->json(['data' => ['id' => $mount->id, 'path_prefix' => $mount->path_prefix]], 201);
    }

    public function destroy(Request $request, string $site, string $mount, PathMounts $mounts): JsonResponse
    {
        $function = $this->function($request, $site, 'edge.manage');
        $mounts->delete(Mount::query()->where('function_site_id', $function->id)->findOrFail(strtolower($mount)));

        return response()->json(['data' => null]);
    }

    private function function(Request $request, string $siteId, ?string $permission = null): SiteData
    {
        $site = $this->site($request, strtolower($siteId), $permission);
        abort_unless($site->runtime->isFunction(), 404, 'Not a function.');

        return $site;
    }
}
