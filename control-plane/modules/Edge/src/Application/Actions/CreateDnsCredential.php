<?php

namespace Falak\Edge\Application\Actions;

use Falak\Edge\Domain\Models\DnsCredential;
use Falak\Identity\Contracts\AuditLog;
use SensitiveParameter;

final class CreateDnsCredential
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(string $organizationId, string $provider, string $name, #[SensitiveParameter] string $apiToken, ?string $userId): DnsCredential
    {
        $credential = DnsCredential::query()->create([
            'organization_id' => $organizationId,
            'provider' => $provider,
            'name' => $name,
            'api_token' => $apiToken,
            'created_by' => $userId,
        ]);

        $this->audit->record('edge.dns_credential_created', 'dns_credential', $credential->id, ['provider' => $provider, 'name' => $name], $organizationId);

        return $credential;
    }
}
