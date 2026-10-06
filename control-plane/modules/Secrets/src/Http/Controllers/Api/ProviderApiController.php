<?php

namespace Falak\Secrets\Http\Controllers\Api;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Secrets\Application\Actions\DeleteSecretProvider;
use Falak\Secrets\Application\Actions\SaveSecretProvider;
use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Domain\Policies\SecretPolicy;
use Falak\Secrets\Http\Controllers\PresentsProviders;
use Falak\Secrets\Http\Requests\ProviderRules;
use Falak\Secrets\Infrastructure\ExternalSecretProviders;
use Falak\Secrets\Infrastructure\Providers\ProviderFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public API v1: secret providers. Responses never carry credentials (only which ones are stored). Providers of
 * other organizations are reported as not found.
 */
final class ProviderApiController extends Controller
{
    use PresentsProviders;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    /** GET /api/v1/secrets/providers */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, SecretPolicy::VIEW);

        $usage = $this->providerUsage($organizationId);
        $providers = SecretProvider::query()->where('organization_id', $organizationId)->orderBy('name')->get();

        return response()->json(['data' => $providers->map(fn (SecretProvider $provider) => $this->presentProvider($provider, $usage))->values()]);
    }

    public function show(Request $request, string $provider): JsonResponse
    {
        return response()->json(['data' => $this->presentProvider($this->resolve($request, $provider, SecretPolicy::VIEW))]);
    }

    public function store(Request $request, SaveSecretProvider $save): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, SecretPolicy::MANAGE);

        $provider = $save($organizationId, null, $request->validate(ProviderRules::create()), $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $this->presentProvider($provider)], 201);
    }

    public function update(Request $request, string $provider, SaveSecretProvider $save): JsonResponse
    {
        $model = $this->resolve($request, $provider, SecretPolicy::MANAGE);
        $model = $save($model->organization_id, $model, $request->validate(ProviderRules::update()), $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $this->presentProvider($model)]);
    }

    public function destroy(Request $request, string $provider, DeleteSecretProvider $delete): JsonResponse
    {
        $delete($this->resolve($request, $provider, SecretPolicy::MANAGE));

        return response()->json(null, 204);
    }

    /** POST /api/v1/secrets/providers/{provider}/test */
    public function test(Request $request, string $provider, ExternalSecretProviders $providers): JsonResponse
    {
        $model = $this->resolve($request, $provider, SecretPolicy::MANAGE);

        try {
            $providers->test($model);
        } catch (ProviderFailure $e) {
            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->presentProvider($model->refresh())]);
    }

    private function resolve(Request $request, string $provider, string $permission): SecretProvider
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, SecretPolicy::VIEW);

        $model = SecretProvider::query()->where('organization_id', $organizationId)->find(strtolower($provider)) ?? throw new NotFoundHttpException('Secret provider not found.');
        $this->access->authorize($request->user(), $organizationId, $permission);

        return $model;
    }
}
