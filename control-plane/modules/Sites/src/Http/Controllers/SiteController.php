<?php

namespace Kiln\Sites\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Application\Actions\CreateSite;
use Kiln\Sites\Application\Actions\DeleteSite;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Http\Requests\StoreSiteRequest;
use Kiln\SourceControl\Contracts\SourceControlGateway;

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
