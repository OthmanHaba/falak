<?php

namespace Falak\Secrets\Http\Controllers\Api;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Secrets\Application\Actions\CreateSecret;
use Falak\Secrets\Application\Actions\DeleteSecret;
use Falak\Secrets\Application\Actions\RevealSecret;
use Falak\Secrets\Application\Actions\RollBackSecret;
use Falak\Secrets\Application\Actions\SetSecretValue;
use Falak\Secrets\Application\Scopes;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Falak\Secrets\Contracts\SecretScope;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Policies\SecretPolicy;
use Falak\Secrets\Http\Controllers\PresentsSecrets;
use Falak\Secrets\Http\Requests\SecretRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public API v1 (Sanctum tokens; abilities are permission names): secrets. Responses carry metadata only; reveal
 * needs a token created with the secrets.reveal ability itself (a "*" token is not enough). Secrets outside the
 * token's organization are reported as not found.
 */
final class SecretApiController extends Controller
{
    use PresentsSecrets;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly Scopes $scopes,
    ) {}

    /** GET /api/v1/secrets[?scope=&scope_id=] */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, SecretPolicy::VIEW);

        $filter = $request->validate([
            'scope' => ['nullable', Rule::enum(SecretScope::class)],
            'scope_id' => ['nullable', 'string', 'max:26'],
        ]);

        $secrets = Secret::query()->where('organization_id', $organizationId)
            ->when($filter['scope'] ?? null, fn ($query, $scope) => $query->where('scope_type', $scope))
            ->when($filter['scope_id'] ?? null, fn ($query, $id) => $query->where('scope_id', strtolower($id)))
            ->orderBy('name')->get();
        Secret::withCurrentVersionDates($secrets);

        return response()->json(['data' => $secrets->map(fn (Secret $secret) => $this->presentSecret($secret, $this->scopes))->values()]);
    }

    public function show(Request $request, string $secret): JsonResponse
    {
        return response()->json(['data' => $this->presentSecret($this->resolve($request, $secret, SecretPolicy::VIEW), $this->scopes)]);
    }

    public function store(Request $request, CreateSecret $create): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, SecretPolicy::MANAGE);

        $data = $request->validate(SecretRules::create(), SecretRules::messages());
        $secret = $create($organizationId, SecretScope::from($data['scope']), (string) $data['scope_id'], $data, $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $this->presentSecret($secret, $this->scopes)], 201);
    }

    /** PUT /api/v1/secrets/{secret}/value: a new version. */
    public function setValue(Request $request, string $secret, SetSecretValue $set): JsonResponse
    {
        $model = $this->resolve($request, $secret, SecretPolicy::MANAGE);
        $version = $set($model, (string) $request->validate(SecretRules::value())['value'], $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $this->presentSecret($model->refresh(), $this->scopes) + ['version' => $version->version]]);
    }

    /** POST /api/v1/secrets/{secret}/rollback {version} */
    public function rollback(Request $request, string $secret, RollBackSecret $rollBack): JsonResponse
    {
        $model = $this->resolve($request, $secret, SecretPolicy::MANAGE);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $version = $rollBack($model, (int) $data['version'], $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $this->presentSecret($model->refresh(), $this->scopes) + ['version' => $version->version]]);
    }

    public function destroy(Request $request, string $secret, DeleteSecret $delete): JsonResponse
    {
        $delete($this->resolve($request, $secret, SecretPolicy::MANAGE));

        return response()->json(null, 204);
    }

    /** POST /api/v1/secrets/{secret}/reveal[?version=] */
    public function reveal(Request $request, string $secret, RevealSecret $reveal): JsonResponse
    {
        $model = $this->resolve($request, $secret, SecretPolicy::REVEAL);
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! in_array(SecretPolicy::REVEAL, (array) $token->abilities, true)) {
            throw new AuthorizationException('Revealing secrets needs a token created with the secrets.reveal ability.');
        }

        $accessor = SecretAccessor::apiToken((string) $token->getKey(), (string) $request->user()->getAuthIdentifier(), "API token \"{$token->name}\"", $request->ip());

        return response()->json(['data' => $reveal($model, $request->integer('version') ?: null, $accessor)])->header('Cache-Control', 'no-store');
    }

    private function resolve(Request $request, string $secret, string $permission): Secret
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, SecretPolicy::VIEW);

        $model = Secret::query()->where('organization_id', $organizationId)->find(strtolower($secret)) ?? throw new NotFoundHttpException('Secret not found.');
        $this->access->authorize($request->user(), $organizationId, $permission);

        return $model;
    }
}
