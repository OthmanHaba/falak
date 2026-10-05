<?php

namespace Falak\Providers\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Providers\Domain\Models\ProviderCredential;
use Falak\Providers\Events\ProviderCredentialRemoved;

final class RemoveCredential
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(ProviderCredential $credential): void
    {
        $credential->delete();

        $this->audit->record('provider_credential.deleted', 'provider_credential', $credential->id, [
            'name' => $credential->name,
            'provider' => $credential->provider->value,
        ], $credential->organization_id);

        ProviderCredentialRemoved::dispatch($credential->organization_id, $credential->id, $credential->provider->value);
    }
}
