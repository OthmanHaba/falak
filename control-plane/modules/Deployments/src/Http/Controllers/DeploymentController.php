<?php

namespace Kiln\Deployments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Application\Orchestration\Orchestrator;
use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\OutputLine;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Deployments\Domain\Models\SiteSettings;
use Kiln\Deployments\Domain\Policies\DeploymentPermissions;
use Kiln\Kernel\Http\Controller;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteHeaders;

final class DeploymentController extends Controller
{
    use PresentsDeployments, ResolvesSites;

    public function __construct(private readonly SiteHeaders $headers) {}

    /**
     * JSON for the canvas panel's Deployments tab; a browser visit opens that tab (legacy page for unplaced sites).
     */
    public function index(Request $request, string $site): Response|JsonResponse|RedirectResponse
    {
        $data = $this->site($request->user(), $site);

        if ($this->wantsPanelJson($request)) {
            return response()->json(['data' => $this->overview($request, $data)]);
        }

        if (($panel = Deployment::path($data->id)) !== "/sites/{$data->id}/deployments") {
            return redirect($panel);
        }

        return Inertia::render('Deployments/Index', [
            'site' => $this->headers->for($data->id),
            ...$this->overview($request, $data),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(Request $request, SiteData $data): array
    {
        $settings = SiteSettings::for($data);

        $active = Deployment::query()->where('site_id', $data->id)->whereIn('status', DeploymentStatus::active())->latest('number')->first();
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
            'canDeploy' => $data->repository !== null || ($data->runtime->isContainer() && $data->dockerImage !== null),
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
    public function show(Request $request, string $site, string $deployment): Response|JsonResponse|RedirectResponse
    {
        $data = $this->site($request->user(), $site);
        $model = $this->deployment($request->user(), $deployment, $data->id);

        $props = [
            'deployment' => $this->deploymentResource($model),
            'targets' => $this->targetsResource($model),
            'steps' => $this->globalSteps($model),
            'lines' => $model->output()->limit(5000)->get()->map(fn (OutputLine $l) => $l->toLine())->values(),
            'can' => [
                'cancel' => in_array($model->status, [DeploymentStatus::Queued, DeploymentStatus::Building], true) && $this->can($request->user(), $data, DeploymentPermissions::CREATE),
                'redeploy' => $this->can($request->user(), $data, DeploymentPermissions::CREATE),
                'rollback' => $model->release_id !== null && $model->status === DeploymentStatus::Succeeded && $this->can($request->user(), $data, DeploymentPermissions::ROLLBACK),
            ],
        ];

        if ($this->wantsPanelJson($request)) {
            return response()->json(['data' => $props]);
        }

        if (($panel = Deployment::path($data->id, $model->id)) !== "/sites/{$data->id}/deployments/{$model->id}") {
            return redirect($panel);
        }

        return Inertia::render('Deployments/Show', ['site' => $this->headers->for($data->id), ...$props]);
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
                ? response()->json(['message' => 'Only queued deployments or deployments still building can be cancelled.'], 422)
                : back()->withErrors(['deployment' => 'Only queued deployments or deployments still building can be cancelled.']);
        }

        return $this->wantsPanelJson($request) ? response()->json(['data' => $this->deploymentResource($model->refresh())]) : back();
    }
}
