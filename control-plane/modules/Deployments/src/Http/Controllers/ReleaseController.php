<?php

namespace Falak\Deployments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Domain\Enums\ReleaseStatus;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Deployments\Domain\Policies\DeploymentPermissions;
use Falak\Kernel\Http\Controller;

final class ReleaseController extends Controller
{
    use ResolvesSites;

    public function index(Request $request, string $site): JsonResponse|RedirectResponse
    {
        $data = $this->site($request->user(), $site);

        $releases = Release::query()->where('site_id', $data->id)->where('status', '!=', ReleaseStatus::Pending)
            ->orderByRaw("case when status = 'active' then 0 else 1 end")->orderByDesc('activated_at')->orderByDesc('created_at')->orderByDesc('id')
            ->limit(50)->get();

        $props = [
            'releases' => $releases->map(fn (Release $r) => $r->toApi())->values(),
            'keep' => SiteSettings::for($data)->keep_releases,
            'can' => ['rollback' => $this->can($request->user(), $data, DeploymentPermissions::ROLLBACK)],
        ];

        // Releases are listed in the panel's Deployments tab (and the Rollback dialog).
        return $this->wantsPanelJson($request) ? response()->json(['data' => $props]) : redirect(Deployment::path($data->id));
    }

    public function rollback(Request $request, string $site, string $release, TriggerDeployment $trigger): RedirectResponse|JsonResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::ROLLBACK);
        $deployment = $trigger($data, Trigger::Rollback, requestedBy: (string) $request->user()?->getAuthIdentifier(), releaseId: $release);

        return $this->wantsPanelJson($request)
            ? response()->json(['data' => ['id' => $deployment->id, 'number' => $deployment->number, 'status' => $deployment->status->value, 'url' => $deployment->url()]], 201)
            : redirect(Deployment::path($data->id, $deployment->id));
    }
}
