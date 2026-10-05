<?php

namespace Falak\Deployments\Http\Controllers;

use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Application\Orchestration\Orchestrator;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\OutputLine;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Deployments\Domain\Policies\DeploymentPermissions;
use Falak\Kernel\Http\Controller;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DeploymentController extends Controller
{
    use PresentsDeployments, ResolvesSites;

    /**
     * JSON for the canvas panel's Deployments tab; a browser visit opens that tab (legacy page for unplaced sites).
     */
    public function index(Request $request, string $site): JsonResponse|RedirectResponse
    {
        $data = $this->site($request->user(), $site);

        if ($this->wantsPanelJson($request)) {
            return response()->json(['data' => $this->overview($request, $data)]);
        }

        $panel = Deployment::path($data->id);

        return redirect($panel !== "/sites/{$data->id}/deployments" ? $panel : '/projects');
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(Request $request, SiteData $data): array
    {
        $settings = SiteSettings::for($data);

        $active = Deployment::query()->where('site_id', $data->id)->whereIn('status', DeploymentStatus::occupying())->latest('number')->first();
        $queued = Deployment::query()->where('site_id', $data->id)->where('status', DeploymentStatus::Queued)->orderBy('number')->get();
        $history = Deployment::query()->where('site_id', $data->id)->whereIn('status', [DeploymentStatus::Succeeded, DeploymentStatus::Failed, DeploymentStatus::Cancelled])
            ->orderByDesc('number')->paginate(20)->withQueryString();

        return [
            'active' => $active ? [...$this->deploymentResource($active), 'targets' => $this->targetsResource($active)] : null,
            'queued' => $queued->map(fn (Deployment $d) => $this->deploymentResource($d))->values(),
            'history' => [
                'data' => $history->getCollection()->map(fn (Deployment $d) => $this->deploymentResource($d))->values(),
                'links' => ['prev' => $history->previousPageUrl(), 'next' => $history->nextPageUrl()],
            ],
            'current' => Release::current($data->id)?->toApi(),
            'strategy' => $settings->effectiveStrategy($data)->label(),
            'defaultBranch' => $data->branch,
            'canDeploy' => $data->hasDeploySource(),
            'can' => [
                'create' => $this->can($request->user(), $data, DeploymentPermissions::CREATE),
                'cancel' => $this->can($request->user(), $data, DeploymentPermissions::CREATE),
                'rollback' => $this->can($request->user(), $data, DeploymentPermissions::ROLLBACK),
            ],
        ];
    }

    public function store(Request $request, string $site, TriggerDeployment $trigger): RedirectResponse|JsonResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::CREATE);
        $input = $request->validate([
            'branch' => ['nullable', 'string', 'max:255', 'regex:/^[^\s~^:?*\[\\\\]+$/'],
            'commit' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{7,64}$/'],
        ]);

        $deployment = $trigger($data, Trigger::Manual, $input['branch'] ?? null, $input['commit'] ?? null, requestedBy: (string) $request->user()?->getAuthIdentifier());

        if ($this->wantsPanelJson($request)) {
            return response()->json(['data' => $this->deploymentResource($deployment)], 201);
        }

        return redirect(Deployment::path($data->id, $deployment->id));
    }

    /**
     * JSON for the panel's Deploy view; a browser visit opens it (legacy page for unplaced sites).
     */
    public function show(Request $request, string $site, string $deployment): JsonResponse|RedirectResponse
    {
        $data = $this->site($request->user(), $site);
        $model = $this->deployment($request->user(), $deployment, $data->id);

        $props = [
            'deployment' => $this->deploymentResource($model),
            'targets' => $this->targetsResource($model),
            'steps' => $this->globalSteps($model),
            'lines' => $model->output()->limit(5000)->get()->map(fn (OutputLine $l) => $l->toLine())->values(),
            'can' => [
                'cancel' => in_array($model->status, [DeploymentStatus::Queued, DeploymentStatus::Waiting, DeploymentStatus::Building], true) && $this->can($request->user(), $data, DeploymentPermissions::CREATE),
                'redeploy' => $this->can($request->user(), $data, DeploymentPermissions::CREATE),
                'rollback' => $model->release_id !== null && $model->status === DeploymentStatus::Succeeded && $this->can($request->user(), $data, DeploymentPermissions::ROLLBACK),
            ],
        ];

        if ($this->wantsPanelJson($request)) {
            return response()->json(['data' => $props]);
        }

        $panel = Deployment::path($data->id, $model->id);

        return redirect($panel !== "/sites/{$data->id}/deployments/{$model->id}" ? $panel : '/projects');
    }

    /**
     * Polling fallback for the live page: state + output after the cursor.
     */
    public function state(Request $request, string $site, string $deployment): JsonResponse
    {
        $data = $this->site($request->user(), $site);
        $model = $this->deployment($request->user(), $deployment, $data->id);
        $after = max(0, (int) $request->query('after', '0'));
        $lines = $model->output()->where('id', '>', $after)->limit((int) config('deployments.output_page_size', 1000))->get()->map(fn (OutputLine $l) => $l->toLine())->values();

        return response()->json(['data' => [
            'deployment' => $this->deploymentResource($model),
            'targets' => $this->targetsResource($model),
            'steps' => $this->globalSteps($model),
            'lines' => $lines,
        ]]);
    }

    public function cancel(Request $request, string $site, string $deployment, Orchestrator $orchestrator): RedirectResponse|JsonResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::CREATE);
        $model = $this->deployment($request->user(), $deployment, $data->id);

        if (! $orchestrator->cancel($model->id)) {
            return $this->wantsPanelJson($request)
                ? response()->json(['message' => 'Only queued or waiting deployments, or deployments still building, can be cancelled.'], 422)
                : back()->withErrors(['deployment' => 'Only queued or waiting deployments, or deployments still building, can be cancelled.']);
        }

        return $this->wantsPanelJson($request) ? response()->json(['data' => $this->deploymentResource($model->refresh())]) : back();
    }
}
