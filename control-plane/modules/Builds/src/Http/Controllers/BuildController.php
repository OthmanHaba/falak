<?php

namespace Falak\Builds\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Builds\Application\Actions\CancelBuild;
use Falak\Builds\Contracts\BuildService;
use Falak\Builds\Domain\Models\Build;
use Falak\Builds\Domain\Policies\BuildPolicy;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;

final class BuildController extends Controller
{
    use PresentsBuilds;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly SiteDirectory $sites,
    ) {}

    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, BuildPolicy::VIEW);

        $siteNames = collect($this->sites->forOrganization($organizationId))->mapWithKeys(fn (SiteData $s) => [$s->id => $s->name])->all();
        $siteId = $request->string('site')->toString();

        $builds = Build::query()->with('builder')
            ->where('organization_id', $organizationId)
            ->when($siteId !== '', fn ($q) => $q->where('site_id', $siteId))
            ->latest()->orderByDesc('id')
            ->paginate(30)->withQueryString();

        return Inertia::render('Builds/Index', [
            'builds' => [
                'data' => $builds->getCollection()->map(fn (Build $b) => $this->buildResource($b, $siteNames))->values(),
                'links' => ['prev' => $builds->previousPageUrl(), 'next' => $builds->nextPageUrl()],
                'total' => $builds->total(),
            ],
            'sites' => collect($siteNames)->map(fn (string $name, string $id) => ['id' => $id, 'name' => $name])->values(),
            'filters' => ['site' => $siteId ?: null],
        ]);
    }

    public function show(Request $request, Build $build, BuildService $service): Response
    {
        $this->authorize('view', $build);
        $build->load('builder');
        $site = $this->sites->find($build->site_id);

        return Inertia::render('Builds/Show', [
            'build' => $this->buildResource($build, $site ? [$site->id => $site->name] : []),
            'lines' => $service->output($build->id, 0, 5000),
            'can' => ['cancel' => $request->user()?->can('cancel', $build) ?? false],
        ]);
    }

    public function output(Request $request, Build $build, BuildService $service): JsonResponse
    {
        $this->authorize('view', $build);
        $build->load('builder');

        return response()->json(['data' => [
            'build' => $this->buildResource($build),
            'lines' => $service->output($build->id, max(0, (int) $request->query('after', '0'))),
        ]]);
    }

    public function cancel(Request $request, Build $build, CancelBuild $cancel): RedirectResponse
    {
        $this->authorize('cancel', $build);
        $cancel($build, 'Cancelled by '.($request->user()?->getAttribute('name') ?? 'a user').'.', (string) $request->user()?->getAuthIdentifier());

        return back();
    }
}
