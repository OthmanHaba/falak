<?php

namespace Falak\Secrets\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Identity\Contracts\Reauthentication;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Contracts\Data\ServiceData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Secrets\Application\Actions\CreateSecret;
use Falak\Secrets\Application\Actions\DeleteSecret;
use Falak\Secrets\Application\Actions\DisableSecretVersion;
use Falak\Secrets\Application\Actions\PromoteVariable;
use Falak\Secrets\Application\Actions\RevealSecret;
use Falak\Secrets\Application\Actions\RollBackSecret;
use Falak\Secrets\Application\Actions\SetSecretValue;
use Falak\Secrets\Application\Actions\UpdateSecret;
use Falak\Secrets\Application\Scopes;
use Falak\Secrets\Application\SecretUsage;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Falak\Secrets\Contracts\SecretScope;
use Falak\Secrets\Domain\Models\AccessLogEntry;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Domain\Policies\SecretPolicy;
use Falak\Secrets\Http\Requests\SecretRules;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Secrets pages (per project, and the organization's own) and their JSON endpoints. Values never reach the
 * browser except through reveal, which needs secrets.reveal and a recent re-authentication, and is logged.
 */
final class SecretController extends Controller
{
    use PresentsSecrets;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly Scopes $scopes,
        private readonly Reauthentication $reauthentication,
    ) {}

    /** GET /projects/{project}/settings/secrets */
    public function project(Request $request, string $project, ProjectDirectory $projects, SecretUsage $usage): Response
    {
        $organizationId = $this->organization->requireId();
        $data = $projects->find($project);

        if ($data === null || $data->organizationId !== $organizationId) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($request->user(), $organizationId, SecretPolicy::VIEW);

        $environments = $projects->environments($data->id);
        /** @var list<ServiceData> $services */
        $services = array_values(array_filter(
            array_merge(...array_map(fn ($environment) => $projects->servicesIn($environment->id), $environments) ?: [[]]),
            fn (ServiceData $service) => $service->kind === ServiceKind::Site,
        ));

        $secrets = Secret::query()->where('organization_id', $organizationId)
            ->where(function (Builder $query) use ($data, $environments, $services, $organizationId) {
                $query->where(fn (Builder $q) => $q->where('scope_type', SecretScope::Organization->value)->where('scope_id', $organizationId))
                    ->orWhere(fn (Builder $q) => $q->where('scope_type', SecretScope::Project->value)->where('scope_id', $data->id))
                    ->orWhere(fn (Builder $q) => $q->where('scope_type', SecretScope::Environment->value)->whereIn('scope_id', array_map(fn ($e) => $e->id, $environments)))
                    ->orWhere(fn (Builder $q) => $q->where('scope_type', SecretScope::Service->value)->whereIn('scope_id', array_map(fn ($s) => $s->id, $services)));
            })
            ->orderBy('name')->get();

        return Inertia::render('Secrets/Index', [
            'context' => 'project',
            'project' => ['id' => $data->id, 'name' => $data->name, 'production_slug' => ($environments[0] ?? null)?->slug],
            'scopes' => [
                ['scope' => SecretScope::Project->value, 'id' => $data->id, 'label' => 'Project', 'environment_id' => null],
                ...array_map(fn ($environment) => ['scope' => SecretScope::Environment->value, 'id' => $environment->id, 'label' => $environment->name, 'environment_id' => $environment->id], $environments),
                ...array_map(fn (ServiceData $service) => ['scope' => SecretScope::Service->value, 'id' => $service->id, 'label' => $service->name, 'environment_id' => $service->environmentId], $services),
            ],
            'secrets' => $this->list($secrets->all(), $usage),
            'providers' => $this->providerOptions($organizationId),
            'can' => $this->abilities($request, $organizationId),
            'reauth_requires_code' => $this->reauthentication->requiresCode($request->user()),
        ]);
    }

    /** GET /settings/secrets: the organization's own secrets (inherited by every project). */
    public function organization(Request $request, SecretUsage $usage): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, SecretPolicy::VIEW);

        $secrets = Secret::query()->where('organization_id', $organizationId)
            ->where('scope_type', SecretScope::Organization->value)->where('scope_id', $organizationId)
            ->orderBy('name')->get();

        return Inertia::render('Secrets/Index', [
            'context' => 'organization',
            'project' => null,
            'scopes' => [['scope' => SecretScope::Organization->value, 'id' => $organizationId, 'label' => 'Organization', 'environment_id' => null]],
            'secrets' => $this->list($secrets->all(), $usage),
            'providers' => $this->providerOptions($organizationId),
            'can' => $this->abilities($request, $organizationId),
            'reauth_requires_code' => $this->reauthentication->requiresCode($request->user()),
        ]);
    }

    /** GET /secrets/{secret}: metadata, versions, access log and usage (no value). */
    public function show(Request $request, Secret $secret, OrganizationDirectory $directory, SecretUsage $usage): JsonResponse
    {
        $this->authorize('view', $secret);
        $detailed = $request->user()?->can('manage', $secret) ?? false;

        $access = AccessLogEntry::query()->where('secret_id', $secret->id)->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => [
            ...$this->presentSecret($secret, $this->scopes, $usage->of([$secret])[$secret->id] ?? []),
            'versions' => $secret->versions()->limit(100)->get()->map(fn ($version) => $this->presentVersion($secret, $version, $directory))->values(),
            'access_log' => $access->map(fn (AccessLogEntry $entry) => $this->presentAccess($entry, $directory, $detailed))->values(),
        ]])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, CreateSecret $create): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, SecretPolicy::MANAGE);

        $data = $request->validate(SecretRules::create(), SecretRules::messages());
        $secret = $create($organizationId, SecretScope::from($data['scope']), (string) $data['scope_id'], $data, $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $this->presentSecret($secret, $this->scopes)], 201);
    }

    public function update(Request $request, Secret $secret, UpdateSecret $update): JsonResponse
    {
        $this->authorize('manage', $secret);

        $update($secret, $request->validate(SecretRules::metadata(), SecretRules::messages()));

        return response()->json(['data' => $this->presentSecret($secret->refresh(), $this->scopes)]);
    }

    /** POST /secrets/{secret}/versions: a new value (a linked secret: a new reference). */
    public function setValue(Request $request, Secret $secret, SetSecretValue $set): JsonResponse
    {
        $this->authorize('manage', $secret);

        $version = $set($secret, (string) $request->validate(SecretRules::value())['value'], $request->user()?->getAuthIdentifier());

        return response()->json(['data' => ['version' => $version->version]], 201);
    }

    public function restore(Request $request, Secret $secret, int $version, RollBackSecret $rollBack): JsonResponse
    {
        $this->authorize('manage', $secret);

        return response()->json(['data' => ['version' => $rollBack($secret, $version, $request->user()?->getAuthIdentifier())->version]], 201);
    }

    public function disable(Secret $secret, int $version, DisableSecretVersion $disable): JsonResponse
    {
        $this->authorize('manage', $secret);

        $disable($secret, $version);

        return response()->json(null, 204);
    }

    public function destroy(Secret $secret, DeleteSecret $delete): JsonResponse
    {
        $this->authorize('manage', $secret);

        $delete($secret);

        return response()->json(null, 204);
    }

    /**
     * POST /secrets/{secret}/reveal: 423 until the user re-authenticated recently (then POST /secrets/reauthenticate
     * and retry).
     */
    public function reveal(Request $request, Secret $secret, RevealSecret $reveal): JsonResponse
    {
        $this->authorize('reveal', $secret);

        if (! $this->reauthentication->confirmedWithin($request, (int) config('secrets.reveal_confirm_seconds', 300))) {
            return response()->json([
                'message' => 'Confirm your identity to reveal secrets.',
                'requires_code' => $this->reauthentication->requiresCode($request->user()),
            ], 423);
        }

        $version = $request->integer('version') ?: null;
        $result = $reveal($secret, $version, SecretAccessor::user((string) $request->user()->getAuthIdentifier(), 'Revealed in the dashboard', $request->ip()));

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function reauthenticate(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['nullable', 'string', 'max:16']]);

        $this->reauthentication->confirm($request, (string) $data['password'], $data['code'] ?? null);

        return response()->json(null, 204);
    }

    /** GET /secrets/promotable?service_id=: the plain-value variable names of a site service (names only). */
    public function promotable(Request $request, ProjectDirectory $projects, SiteDirectory $sites): JsonResponse
    {
        $service = $this->siteService($request, (string) $request->query('service_id', ''), $projects);

        $variables = array_map('strval', $sites->environment($service->refId)->variables ?? []);
        $keys = array_keys(array_filter($variables, fn (string $value) => $value !== '' && preg_match(VariableReferences::PATTERN, $value) !== 1));

        return response()->json(['data' => ['keys' => array_values(array_map('strval', $keys))]]);
    }

    /** POST /secrets/promote: move a site variable's value into a service secret, leaving a reference behind. */
    public function promote(Request $request, ProjectDirectory $projects, PromoteVariable $promote): JsonResponse
    {
        $data = $request->validate(SecretRules::promote(), SecretRules::messages());
        $service = $this->siteService($request, (string) $data['service_id'], $projects);

        $this->access->authorize($request->user(), $service->organizationId, SecretPolicy::MANAGE);
        $this->access->authorize($request->user(), $service->organizationId, 'sites.env.manage');

        $secret = $promote($service->refId, (string) $data['key'], (string) $data['name'], (bool) ($data['sensitive'] ?? true), $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $this->presentSecret($secret, $this->scopes)], 201);
    }

    private function siteService(Request $request, string $serviceId, ProjectDirectory $projects): ServiceData
    {
        $organizationId = $this->organization->requireId();
        $service = $serviceId !== '' ? $projects->findService($serviceId) : null;

        if ($service === null || $service->organizationId !== $organizationId || $service->kind !== ServiceKind::Site) {
            throw ValidationException::withMessages(['service_id' => 'Choose a site service of this organization.']);
        }

        $this->access->authorize($request->user(), $organizationId, SecretPolicy::VIEW);

        return $service;
    }

    /**
     * @param  list<Secret>  $secrets
     * @return list<array<string, mixed>>
     */
    private function list(array $secrets, SecretUsage $usage): array
    {
        $used = $usage->of($secrets);
        Secret::withCurrentVersionDates($secrets);

        return array_map(fn (Secret $secret) => $this->presentSecret($secret, $this->scopes, $used[$secret->id] ?? []), $secrets);
    }

    /**
     * The organization's providers, for linking a secret (names and types only).
     *
     * @return list<array{id: string, name: string, type: string, scheme: string, example: string, status: string}>
     */
    private function providerOptions(string $organizationId): array
    {
        return SecretProvider::query()->where('organization_id', $organizationId)->orderBy('name')->get()
            ->map(fn (SecretProvider $provider) => [
                'id' => $provider->id,
                'name' => $provider->name,
                'type' => $provider->type->value,
                'scheme' => $provider->type->scheme(),
                'example' => $provider->type->example(),
                'status' => $provider->status->value,
            ])->values()->all();
    }

    /**
     * @return array{manage: bool, reveal: bool, promote: bool}
     */
    private function abilities(Request $request, string $organizationId): array
    {
        $manage = $this->access->can($request->user(), $organizationId, SecretPolicy::MANAGE);

        return [
            'manage' => $manage,
            'reveal' => $this->access->can($request->user(), $organizationId, SecretPolicy::REVEAL),
            'promote' => $manage && $this->access->can($request->user(), $organizationId, 'sites.env.manage'),
        ];
    }
}
