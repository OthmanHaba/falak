<?php

namespace Kiln\Functions\Http\Controllers\Api;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kiln\Deployments\Contracts\DeploymentDirectory;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Functions\Application\Actions\DeployCode;
use Kiln\Functions\Application\Actions\DeployVersion;
use Kiln\Functions\Application\FunctionStore;
use Kiln\Functions\Application\StaleVersion;
use Kiln\Functions\Domain\Models\CloudFunction;
use Kiln\Functions\Domain\Models\FunctionSchedule;
use Kiln\Functions\Domain\Models\FunctionVersion;
use Kiln\Functions\FunctionsServiceProvider as Permissions;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteDomains;

/**
 * Public API v1 for functions, used by `kiln fn …` (agent/internal/cli). Sanctum tokens pinned to one
 * organization; abilities are permission names. Responses mirror the panel's JSON (FunctionController).
 */
final class FunctionApiController extends Controller
{
    private const SETTINGS = ['min_instances', 'max_instances', 'concurrency', 'idle_timeout_s', 'memory_mb', 'cpus', 'request_timeout_s'];

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly SiteDirectory $sites,
        private readonly FunctionStore $functions,
        private readonly DeploymentDirectory $deployments,
        private readonly SiteDomains $domains,
    ) {}

    /** GET /api/v1/functions */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, Permissions::VIEW);
        $sites = array_values(array_filter($this->sites->forOrganization($organizationId), fn (SiteData $site) => $site->runtime->isFunction()));
        $domains = $this->domains->primaryDomains(array_map(fn (SiteData $site) => $site->id, $sites));
        $out = [];

        foreach ($sites as $site) {
            $function = $this->functions->ensure($site);
            $live = $this->live($site, $function);
            $out[] = [
                'id' => $site->id,
                'name' => $site->name,
                'slug' => $site->slug,
                'runtime' => $function->runtime,
                'entrypoint' => $function->entrypoint,
                'live' => $live ? ['number' => $live->number, 'hash' => $live->hash, 'short_hash' => substr($live->hash, 0, 7)] : null,
                'url' => isset($domains[$site->id]) ? "https://{$domains[$site->id]}" : null,
            ];
        }

        return response()->json(['data' => $out]);
    }

    /** GET /api/v1/functions/{site} */
    public function show(Request $request, string $site): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::VIEW);
        $head = $function->head();
        $live = $this->live($data, $function);
        $runtime = (array) config("functions.runtimes.{$function->runtime}", []);
        $domain = $this->domains->primaryDomains([$data->id])[$data->id] ?? null;

        return response()->json(['data' => [
            'site' => ['id' => $data->id, 'name' => $data->name, 'slug' => $data->slug],
            'runtime' => ['key' => $function->runtime, 'label' => $runtime['label'] ?? $function->runtime, 'language' => $runtime['language'] ?? 'typescript', 'family' => $runtime['family'] ?? 'ts'],
            'entrypoint' => $function->entrypoint,
            'url' => $domain !== null ? "https://{$domain}" : null,
            'head' => $head ? [...$head->summary(), 'files' => $head->files] : null,
            'live' => $live?->summary(),
            'settings' => array_intersect_key($function->only(self::SETTINGS), array_flip(self::SETTINGS)),
            'schedules' => FunctionSchedule::query()->where('function_id', $function->id)->orderBy('created_at')->get()
                ->map(fn (FunctionSchedule $s) => $s->present($data->slug))->values(),
        ]])->header('Cache-Control', 'no-store');
    }

    /** POST /api/v1/functions/{site}/deploy {files, message?, base_version_id?, force?} */
    public function deploy(Request $request, string $site, DeployCode $deploy): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::DEPLOY);
        $this->access->authorize($request->user(), $data->organizationId, 'deployments.create');
        $input = $request->validate([
            'files' => ['required', 'array'],
            'message' => ['nullable', 'string', 'max:500'],
            'base_version_id' => ['nullable', 'string', 'size:26'],
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $deploy(
                $data,
                $function,
                $input['files'],
                $input['message'] ?? null,
                // No base: the caller deploys on top of the newest version.
                isset($input['base_version_id']) ? strtolower($input['base_version_id']) : $function->head()?->id,
                $request->user()?->getAuthIdentifier(),
                $request->user()?->name ?? null,
                force: (bool) ($input['force'] ?? false),
            );
        } catch (StaleVersion $e) {
            return response()->json(['message' => $e->getMessage(), 'head' => [...$e->head->summary(), 'files' => $e->head->files]], 409);
        }

        return response()->json([
            'data' => ['version' => $result['version']->summary(), 'created' => $result['created'], 'deployment_id' => $result['deployment_id']],
            'warnings' => $result['warnings'],
        ], $result['created'] ? 201 : 200);
    }

    /** GET /api/v1/functions/{site}/versions */
    public function versions(Request $request, string $site): JsonResponse
    {
        [, $function] = $this->resolve($request->user(), $site, Permissions::VIEW);
        $versions = FunctionVersion::query()->where('function_id', $function->id)->orderByDesc('number')->limit(200)
            ->get(['id', 'function_id', 'number', 'hash', 'size', 'message', 'author_name', 'created_at']);

        return response()->json(['data' => $versions->map(fn (FunctionVersion $version) => $version->summary())->values()]);
    }

    /** GET /api/v1/functions/{site}/versions/{number} */
    public function version(Request $request, string $site, int $number): JsonResponse
    {
        [, $function] = $this->resolve($request->user(), $site, Permissions::VIEW);
        $version = FunctionVersion::query()->where('function_id', $function->id)->where('number', $number)->firstOrFail();

        return response()->json(['data' => [...$version->summary(), 'entrypoint' => $version->entrypoint, 'files' => $version->files]]);
    }

    /** POST /api/v1/functions/{site}/versions/{number}/deploy (rollback) */
    public function deployVersion(Request $request, string $site, int $number, DeployVersion $deploy): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::DEPLOY);
        $this->access->authorize($request->user(), $data->organizationId, 'deployments.create');
        $version = FunctionVersion::query()->where('function_id', $function->id)->where('number', $number)->firstOrFail();
        $isHead = $function->head()?->id === $version->id;

        return response()->json(['data' => [
            'deployment_id' => $deploy($data, $version, $request->user()?->getAuthIdentifier(), $request->user()?->name ?? null, rollback: ! $isHead),
        ]], 201);
    }

    /** POST /api/v1/functions/{site}/schedules/{schedule}/run — {schedule} is the schedule's id, key or name. */
    public function run(Request $request, string $site, string $schedule, AgentGateway $agents, Cache $cache): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::DEPLOY);
        $model = $this->schedule($function, $schedule);
        $leader = $this->sites->leader($data->id) ?? throw ValidationException::withMessages(['schedule' => 'The function has no server.']);

        try {
            $handle = $agents->dispatch($leader->serverId, 'fn.run', [
                'site' => $data->slug,
                'schedule' => $model->key(),
                'name' => mb_substr($model->name, 0, 128),
                'cron' => $model->expression,
                'timeout_s' => $model->timeout_s,
            ], $model->timeout_s + 60);
        } catch (AgentUnavailable) {
            throw ValidationException::withMessages(['schedule' => 'The function’s server is not connected.']);
        }

        // Same key as the panel (ScheduleController): runs started either way can be read either way.
        $cache->put("functions:run:{$data->id}:".strtolower($handle->id), $model->id, now()->addDay());

        return response()->json(['data' => ['run_id' => $handle->id, 'schedule' => $model->present($data->slug)]], 202);
    }

    /** GET /api/v1/functions/{site}/runs/{run} */
    public function runStatus(Request $request, string $site, string $run, AgentGateway $agents, Cache $cache): JsonResponse
    {
        [$data] = $this->resolve($request->user(), $site, Permissions::VIEW);
        abort_unless($cache->has("functions:run:{$data->id}:".strtolower($run)), 404, 'Run not found.');

        $status = $agents->status($run);
        $output = $agents->output($run);

        return response()->json(['data' => [
            'status' => $status->status->value,
            'finished' => $status->isFinished(),
            'exit_code' => $status->result['exit_code'] ?? $status->exitCode,
            'duration_ms' => $status->result['duration_ms'] ?? null,
            'error' => $status->error,
            'output' => $output->text(),
        ]])->header('Cache-Control', 'no-store');
    }

    private function live(SiteData $site, CloudFunction $function): ?FunctionVersion
    {
        $hash = $this->deployments->liveCommit($site->id);

        return $hash !== null ? FunctionVersion::query()->where('function_id', $function->id)->where('hash', $hash)->orderByDesc('number')->first() : null;
    }

    private function schedule(CloudFunction $function, string $ref): FunctionSchedule
    {
        $schedules = FunctionSchedule::query()->where('function_id', $function->id)->orderBy('created_at')->get();
        $match = $schedules->first(fn (FunctionSchedule $s) => $s->id === strtolower($ref) || $s->key() === strtolower($ref))
            ?? $schedules->first(fn (FunctionSchedule $s) => mb_strtolower($s->name) === mb_strtolower($ref));

        abort_if($match === null, 404, 'Schedule not found.');

        return $match;
    }

    /**
     * A function site of the current organization, by id or slug; anything else is a 404.
     *
     * @return array{0: SiteData, 1: CloudFunction}
     */
    private function resolve(?Authenticatable $user, string $idOrSlug, string $permission): array
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($user, $organizationId, Permissions::VIEW);
        $site = $this->sites->find(strtolower($idOrSlug));

        if ($site === null || $site->organizationId !== $organizationId) {
            $site = null;

            foreach ($this->sites->forOrganization($organizationId) as $candidate) {
                if ($candidate->slug === strtolower($idOrSlug)) {
                    $site = $candidate;
                    break;
                }
            }
        }

        abort_if($site === null || ! $site->runtime->isFunction(), 404, 'Function not found.');
        $this->access->authorize($user, $organizationId, $permission);

        return [$site, $this->functions->ensure($site)];
    }
}
