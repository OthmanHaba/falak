<?php

namespace Falak\Providers\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Providers\Application\Actions\AddCredential;
use Falak\Providers\Application\Actions\RemoveCredential;
use Falak\Providers\Application\Actions\UpdateCredential;
use Falak\Providers\Application\Actions\VerifyCredential;
use Falak\Providers\Contracts\ProviderType;
use Falak\Providers\Domain\CredentialFields;
use Falak\Providers\Domain\Models\ProviderCredential;

final class ProviderCredentialController extends Controller
{
    public function __construct(private readonly CurrentOrganization $organization) {}

    public function index(Request $request, OrganizationAccess $access): Response
    {
        $organizationId = $this->organization->requireId();

        return Inertia::render('Providers/Index', [
            'credentials' => ProviderCredential::query()
                ->forOrganization($organizationId)
                ->orderBy('name')
                ->get()
                ->map(fn (ProviderCredential $credential) => [
                    'id' => $credential->id,
                    'name' => $credential->name,
                    'provider' => $credential->provider->value,
                    'provider_label' => $credential->provider->label(),
                    'status' => $credential->status->value,
                    'last_verified_at' => $credential->last_verified_at?->toIso8601String(),
                    'last_error' => $credential->last_error,
                    'created_at' => $credential->created_at?->toIso8601String(),
                ])
                ->values(),
            'providers' => array_map(fn (ProviderType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'fields' => CredentialFields::for($type),
            ], CredentialFields::apiProviders()),
            'can' => ['manage' => $access->can($request->user(), $organizationId, 'providers.manage')],
        ]);
    }

    public function store(Request $request, AddCredential $add): RedirectResponse
    {
        $organizationId = $this->organization->requireId();

        $provider = ProviderType::tryFrom((string) $request->input('provider'));

        $data = $request->validate([
            'provider' => ['required', Rule::enum(ProviderType::class)->only(CredentialFields::apiProviders())],
            'name' => ['required', 'string', 'max:100', Rule::unique('providers_credentials')->where('organization_id', $organizationId)],
            'credentials' => ['required', 'array'],
            ...($provider ? CredentialFields::rules($provider) : []),
        ]);

        $add($organizationId, ProviderType::from($data['provider']), $data['name'], $data['credentials'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('success', "{$data['name']} connected and verified.");
    }

    public function update(Request $request, string $credential, UpdateCredential $update): RedirectResponse
    {
        $model = $this->find($credential);
        $this->authorize('update', $model);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('providers_credentials')->where('organization_id', $model->organization_id)->ignore($model->id)],
            'credentials' => ['sometimes', 'array'],
            ...CredentialFields::rules($model->provider, required: false),
        ]);

        $update($model, $data['name'] ?? null, $data['credentials'] ?? null);

        return back();
    }

    public function verify(string $credential, VerifyCredential $verify): RedirectResponse
    {
        $model = $this->find($credential);
        $this->authorize('update', $model);

        $ok = $verify($model);

        return $ok
            ? back()->with('success', "{$model->name} verified.")
            : back()->with('error', "{$model->name} could not be verified: {$model->last_error}");
    }

    public function destroy(string $credential, RemoveCredential $remove): RedirectResponse
    {
        $model = $this->find($credential);
        $this->authorize('delete', $model);

        $remove($model);

        return back();
    }

    private function find(string $id): ProviderCredential
    {
        return ProviderCredential::query()->forOrganization($this->organization->requireId())->findOrFail($id);
    }
}
