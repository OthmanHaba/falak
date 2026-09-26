<?php

namespace Kiln\Databases\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Databases\Application\Actions\DeleteStorageProvider;
use Kiln\Databases\Application\Actions\SaveStorageProvider;
use Kiln\Databases\Application\Actions\VerifyStorageProvider;
use Kiln\Databases\Domain\Enums\StorageDriver;
use Kiln\Databases\Domain\Models\StorageProvider;
use Kiln\Databases\Domain\Policies\DatabasesPolicy;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;

final class StorageProviderController extends Controller
{
    use PresentsDatabases;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, DatabasesPolicy::VIEW);

        return Inertia::render('Databases/Storage', [
            'providers' => StorageProvider::query()->where('organization_id', $organizationId)->orderBy('name')->get()
                ->map(fn (StorageProvider $provider) => $this->presentProvider($provider))->values(),
            'drivers' => collect(StorageDriver::cases())->map(fn (StorageDriver $driver) => [
                'value' => $driver->value,
                'label' => $driver->label(),
                'requires_endpoint' => $driver->requiresEndpoint(),
                'default_region' => $driver->defaultRegion(),
                'region_hint' => $driver->regionHint(),
            ])->values(),
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, DatabasesPolicy::STORAGE)],
        ]);
    }

    public function store(Request $request, SaveStorageProvider $save): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, DatabasesPolicy::STORAGE);

        $save($organizationId, $request->validate($this->rules($organizationId, null)), null, $request->user()?->getAuthIdentifier());

        return back();
    }

    public function update(Request $request, StorageProvider $storageProvider, SaveStorageProvider $save): RedirectResponse
    {
        $this->authorize('manageStorage', $storageProvider);

        $save($storageProvider->organization_id, $request->validate($this->rules($storageProvider->organization_id, $storageProvider)), $storageProvider);

        return back();
    }

    public function verify(StorageProvider $storageProvider, VerifyStorageProvider $verify): RedirectResponse
    {
        $this->authorize('manageStorage', $storageProvider);

        $verify($storageProvider);

        return back();
    }

    public function destroy(StorageProvider $storageProvider, DeleteStorageProvider $delete): RedirectResponse
    {
        $this->authorize('manageStorage', $storageProvider);

        $delete($storageProvider);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(string $organizationId, ?StorageProvider $provider): array
    {
        $secret = $provider ? 'nullable' : 'required';

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('databases_storage_providers')->where('organization_id', $organizationId)->ignore($provider?->id)],
            'driver' => ['required', Rule::enum(StorageDriver::class)],
            'region' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'bucket' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/'],
            'prefix' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9!_.*\'()\/-]*$/'],
            'endpoint' => ['nullable', 'required_if:driver,minio', 'url:https', 'max:255'],
            'account_id' => [$provider ? 'nullable' : 'required_if:driver,r2', 'nullable', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'path_style' => ['nullable', 'boolean'],
            'access_key_id' => [$secret, 'string', 'max:256'],
            'secret_access_key' => [$secret, 'string', 'max:256'],
        ];
    }
}
