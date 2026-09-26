<?php

namespace Kiln\Deployments\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Domain\Enums\ReleaseStatus;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Deployments\Domain\Models\SiteSettings;
use Kiln\Deployments\Domain\Policies\DeploymentPermissions;
use Kiln\Kernel\Http\Controller;
use Kiln\Sites\Contracts\SiteHeaders;

final class ReleaseController extends Controller
{
    use ResolvesSites;

    public function index(Request $request, string $site, SiteHeaders $headers): Response
    {
        $data = $this->site($request->user(), $site);

        $releases = Release::query()->where('site_id', $data->id)->where('status', '!=', ReleaseStatus::Pending)
            ->orderByRaw("case when status = 'active' then 0 else 1 end")->orderByDesc('activated_at')->orderByDesc('created_at')
            ->limit(50)->get();

        return Inertia::render('Deployments/Releases', [
            'site' => $headers->for($data->id),
            'releases' => $releases->map(fn (Release $r) => $r->toResource())->values(),
            'keep' => SiteSettings::for($data)->keep_releases,
            'can' => ['rollback' => $this->can($request->user(), $data, DeploymentPermissions::ROLLBACK)],
        ]);
    }

    public function rollback(Request $request, string $site, string $release, TriggerDeployment $trigger): RedirectResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::ROLLBACK);
        $deployment = $trigger($data, Trigger::Rollback, requestedBy: (string) $request->user()?->getAuthIdentifier(), releaseId: $release);

        return redirect("/sites/{$data->id}/deployments/{$deployment->id}");
    }
}
