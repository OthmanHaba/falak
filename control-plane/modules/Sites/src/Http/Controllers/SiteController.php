<?php

namespace Falak\Sites\Http\Controllers;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Application\Actions\CreateSite;
use Falak\Sites\Application\Actions\DeleteSite;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Http\Requests\StoreSiteRequest;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SiteController extends Controller
{
    use PresentsSites;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
    ) {}

    /** The sites list moved to the project canvas. */
    public function index(Request $request): RedirectResponse
    {
        $this->access->authorize($request->user(), $this->organization->requireId(), 'sites.view');

        return redirect('/projects');
    }

    /**
     * Options for the canvas Create picker (JSON); the classic create page moved into the picker on the canvas.
     */
    public function create(Request $request, AgentDirectory $agents, SourceControlGateway $sourceControl): RedirectResponse|JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'sites.create');

        if ($request->wantsJson() && $request->header('X-Inertia') === null) {
            return response()->json(['data' => [
                'options' => $this->options($organizationId, $this->servers, $agents, $sourceControl),
                'can_manage_source_control' => $this->access->can($request->user(), $organizationId, 'source_control.manage'),
            ]]);
        }

        return redirect('/projects');
    }

    public function store(StoreSiteRequest $request, CreateSite $create): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'sites.create');

        $site = $create($organizationId, $request->user()?->getAuthIdentifier(), $request->siteData(), $request->placement());

        return to_route('sites.show', $site)->with('sites.warnings', $create->warnings);
    }

    /**
     * Sites open in their canvas service panel (UI_DESIGN §3 legacy redirects).
     */
    public function show(Site $site): RedirectResponse
    {
        $this->authorize('view', $site);

        return $this->toPanel($site);
    }

    public function destroy(Request $request, Site $site, DeleteSite $delete): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $site);

        $data = $request->validate([
            'name' => ['required', 'string', Rule::in([$site->name])],
            'delete_volumes' => ['sometimes', 'array', 'max:100'],
            'delete_volumes.*' => ['string', 'size:26'],
        ], ['name.in' => 'Type the site name to confirm.']);

        $delete($site, deleteVolumeIds: array_values($data['delete_volumes'] ?? []), actorId: $request->user()?->getAuthIdentifier());

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
