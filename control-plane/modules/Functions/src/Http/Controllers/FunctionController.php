<?php

namespace Kiln\Functions\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kiln\Deployments\Contracts\DeploymentDirectory;
use Kiln\Functions\Application\Actions\DeployCode;
use Kiln\Functions\Application\Actions\DeployVersion;
use Kiln\Functions\Application\Actions\UpdateSettings;
use Kiln\Functions\Application\Code;
use Kiln\Functions\Application\FunctionStore;
use Kiln\Functions\Application\StaleVersion;
use Kiln\Functions\Domain\Models\CloudFunction;
use Kiln\Functions\Domain\Models\FunctionDraft;
use Kiln\Functions\Domain\Models\FunctionVersion;
use Kiln\Functions\FunctionsServiceProvider as Permissions;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;

/**
 * The function panel's Code, Versions and Scaling tabs (JSON).
 */
final class FunctionController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly SiteDirectory $sites,
        private readonly FunctionStore $functions,
    ) {}

    public function show(Request $request, string $site, DeploymentDirectory $deployments): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::VIEW);
        $head = $function->head();
        $liveHash = $deployments->liveCommit($data->id);
        $live = $liveHash !== null ? FunctionVersion::query()->where('function_id', $function->id)->where('hash', $liveHash)->orderByDesc('number')->first() : null;
        $draft = $request->user() ? FunctionDraft::query()->where('function_id', $function->id)->where('user_id', $request->user()->getAuthIdentifier())->first() : null;
        $runtime = (array) config("functions.runtimes.{$function->runtime}", []);

        return response()->json(['data' => [
            'site' => ['id' => $data->id, 'name' => $data->name, 'slug' => $data->slug],
            'runtime' => ['key' => $function->runtime, 'label' => $runtime['label'] ?? $function->runtime, 'language' => $runtime['language'] ?? 'typescript'],
            'entrypoint' => $function->entrypoint,
            'head' => $head ? [...$head->summary(), 'files' => $head->files] : null,
            'live' => $live?->summary(),
            'draft' => $draft ? ['files' => $draft->files, 'base_version_id' => $draft->base_version_id, 'updated_at' => $draft->updated_at->toIso8601String()] : null,
            'settings' => $this->settings($function),
            'bounds' => config('functions.bounds'),
            'limits' => ['max_bytes' => (int) config('functions.max_bytes'), 'max_files' => (int) config('functions.max_files')],
            'can' => [
                'edit' => $this->access->can($request->user(), $data->organizationId, Permissions::EDIT),
                'deploy' => $this->access->can($request->user(), $data->organizationId, Permissions::DEPLOY)
                    && $this->access->can($request->user(), $data->organizationId, 'deployments.create'),
            ],
        ]])->header('Cache-Control', 'no-store');
    }

    /** Autosave of the editor (per user). */
    public function saveDraft(Request $request, string $site): JsonResponse
    {
        [, $function] = $this->resolve($request->user(), $site, Permissions::EDIT);
        $input = $request->validate(['files' => ['required', 'array'], 'base_version_id' => ['nullable', 'string', 'size:26']]);
        $files = Code::files($input['files'], $function->entrypoint);

        $draft = FunctionDraft::query()->updateOrCreate(
            ['function_id' => $function->id, 'user_id' => (string) $request->user()?->getAuthIdentifier()],
            ['files' => $files, 'base_version_id' => isset($input['base_version_id']) ? strtolower($input['base_version_id']) : null],
        );

        return response()->json(['data' => ['updated_at' => $draft->updated_at->toIso8601String()]]);
    }

    public function discardDraft(Request $request, string $site): JsonResponse
    {
        [, $function] = $this->resolve($request->user(), $site, Permissions::EDIT);
        FunctionDraft::query()->where('function_id', $function->id)->where('user_id', (string) $request->user()?->getAuthIdentifier())->delete();

        return response()->json(['data' => null]);
    }

    public function deploy(Request $request, string $site, DeployCode $deploy): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::EDIT);
        $this->authorizeDeploy($request->user(), $data);
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
                isset($input['base_version_id']) ? strtolower($input['base_version_id']) : null,
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

    public function versions(Request $request, string $site): JsonResponse
    {
        [, $function] = $this->resolve($request->user(), $site, Permissions::VIEW);
        $versions = FunctionVersion::query()->where('function_id', $function->id)->orderByDesc('number')->limit(200)
            ->get(['id', 'function_id', 'number', 'hash', 'size', 'message', 'author_name', 'created_at']);

        return response()->json(['data' => $versions->map(fn (FunctionVersion $version) => $version->summary())->values()]);
    }

    public function version(Request $request, string $site, int $number): JsonResponse
    {
        [, $function] = $this->resolve($request->user(), $site, Permissions::VIEW);
        $version = FunctionVersion::query()->where('function_id', $function->id)->where('number', $number)->firstOrFail();

        return response()->json(['data' => [...$version->summary(), 'entrypoint' => $version->entrypoint, 'files' => $version->files]]);
    }

    public function deployVersion(Request $request, string $site, int $number, DeployVersion $deploy): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::DEPLOY);
        $this->authorizeDeploy($request->user(), $data);
        $version = FunctionVersion::query()->where('function_id', $function->id)->where('number', $number)->firstOrFail();
        $isHead = $function->head()?->id === $version->id;

        return response()->json(['data' => [
            'deployment_id' => $deploy($data, $version, $request->user()?->getAuthIdentifier(), $request->user()?->name ?? null, rollback: ! $isHead),
        ]], 201);
    }

    public function updateSettings(Request $request, string $site, UpdateSettings $update): JsonResponse
    {
        [$data, $function] = $this->resolve($request->user(), $site, Permissions::DEPLOY);
        $this->authorizeDeploy($request->user(), $data);
        $input = $request->validate(UpdateSettings::rules());
        $deploymentId = $update($data, $function, $input, $request->user()?->getAuthIdentifier());

        return response()->json(['data' => ['settings' => $this->settings($function->refresh()), 'deployment_id' => $deploymentId]]);
    }

    /**
     * @return array{0: SiteData, 1: CloudFunction}
     */
    private function resolve(?Authenticatable $user, string $siteId, string $permission): array
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($user, $organizationId, Permissions::VIEW);
        $site = $this->sites->find(strtolower($siteId));
        abort_if($site === null || $site->organizationId !== $organizationId || ! $site->runtime->isFunction(), 404, 'Function not found.');
        $this->access->authorize($user, $organizationId, $permission);

        return [$site, $this->functions->ensure($site)];
    }

    /** Deploying a function starts a deployment: it needs Deployments' permission too. */
    private function authorizeDeploy(?Authenticatable $user, SiteData $site): void
    {
        $this->access->authorize($user, $site->organizationId, Permissions::DEPLOY);
        $this->access->authorize($user, $site->organizationId, 'deployments.create');
    }

    /**
     * @return array<string, int|float>
     */
    private function settings(CloudFunction $function): array
    {
        return array_intersect_key($function->only(UpdateSettings::FIELDS), array_flip(UpdateSettings::FIELDS));
    }
}
