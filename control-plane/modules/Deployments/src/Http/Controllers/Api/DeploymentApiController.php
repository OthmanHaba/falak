<?php

namespace Kiln\Deployments\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Domain\Enums\ReleaseStatus;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\OutputLine;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Deployments\Domain\Policies\DeploymentPermissions;
use Kiln\Deployments\Http\Controllers\PresentsDeployments;
use Kiln\Deployments\Http\Controllers\ResolvesSites;
use Kiln\Kernel\Http\Controller;

/**
 * Public API v1 used by the `kiln` CLI (agent/internal/cli/api). Sanctum tokens pinned to one
 * organization; abilities are permission names.
 */
final class DeploymentApiController extends Controller
{
    use PresentsDeployments, ResolvesSites;

    /** GET /api/v1/sites/{site}/deployments */
    public function index(Request $request, string $site): JsonResponse
    {
        $data = $this->site($request->user(), $site);
        $perPage = max(1, min(100, (int) $request->query('per_page', '20')));
        $page = Deployment::query()->where('site_id', $data->id)->orderByDesc('number')->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Deployment $d) => $this->deploymentResource($d))->values(),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** POST /api/v1/sites/{site}/deployments {branch?, commit?} */
    public function store(Request $request, string $site, TriggerDeployment $trigger): JsonResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::CREATE);
        $input = $request->validate([
            'branch' => ['nullable', 'string', 'max:255', 'regex:/^[^\s~^:?*\[\\\\]+$/'],
            'commit' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{7,64}$/'],
        ]);

        $deployment = $trigger($data, Trigger::Api, $input['branch'] ?? null, $input['commit'] ?? null, requestedBy: (string) $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $this->deploymentResource($deployment)], 201);
    }

    /** GET /api/v1/deployments/{deployment} */
    public function show(Request $request, string $deployment): JsonResponse
    {
        $model = $this->deployment($request->user(), $deployment);

        return response()->json(['data' => [
            ...$this->deploymentResource($model),
            'targets' => $this->targetsResource($model),
        ]]);
    }

    /** GET /api/v1/deployments/{deployment}/output?after=<seq> → {data: [OutputLine], meta: {next}} */
    public function output(Request $request, string $deployment): JsonResponse
    {
        $model = $this->deployment($request->user(), $deployment);
        $after = max(0, (int) $request->query('after', '0'));
        $limit = (int) config('deployments.output_page_size', 1000);

        $lines = OutputLine::query()->where('deployment_id', $model->id)->where('id', '>', $after)->orderBy('id')->limit($limit)->get();

        return response()->json([
            'data' => $lines->map(fn (OutputLine $l) => $l->toLine())->values(),
            'meta' => ['next' => $lines->isEmpty() ? $after : (int) $lines->last()->id, 'status' => $model->status->value],
        ]);
    }

    /** POST /api/v1/sites/{site}/rollback {release_id?} */
    public function rollback(Request $request, string $site, TriggerDeployment $trigger): JsonResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::ROLLBACK);
        $input = $request->validate(['release_id' => ['nullable', 'string', 'regex:/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/']]);

        $deployment = $trigger($data, Trigger::Rollback, requestedBy: (string) $request->user()?->getAuthIdentifier(), releaseId: $input['release_id'] ?? null);

        return response()->json(['data' => $this->deploymentResource($deployment)], 201);
    }

    /** GET /api/v1/sites/{site}/releases */
    public function releases(Request $request, string $site): JsonResponse
    {
        $data = $this->site($request->user(), $site);

        $releases = Release::query()->where('site_id', $data->id)->whereIn('status', [ReleaseStatus::Active, ReleaseStatus::Inactive])
            ->orderByRaw("case when status = 'active' then 0 else 1 end")->orderByDesc('activated_at')->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json(['data' => $releases->map(fn (Release $r) => $r->toApi())->values()]);
    }
}
