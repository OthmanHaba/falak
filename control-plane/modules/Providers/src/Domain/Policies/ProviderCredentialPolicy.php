<?php

namespace Falak\Providers\Domain\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Providers\Domain\Models\ProviderCredential;

final class ProviderCredentialPolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, ProviderCredential $credential): bool
    {
        return $this->access->can($user, $credential->organization_id, 'providers.view');
    }

    public function update(Authenticatable $user, ProviderCredential $credential): bool
    {
        return $this->access->can($user, $credential->organization_id, 'providers.manage');
    }

    public function delete(Authenticatable $user, ProviderCredential $credential): bool
    {
        return $this->access->can($user, $credential->organization_id, 'providers.manage');
    }
}
