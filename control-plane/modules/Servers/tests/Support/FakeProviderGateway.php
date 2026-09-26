<?php

namespace Kiln\Servers\Tests\Support;

use Kiln\Providers\Contracts\Data\CredentialSummary;
use Kiln\Providers\Contracts\Data\MachineSpec;
use Kiln\Providers\Contracts\Exceptions\ProviderException;
use Kiln\Providers\Contracts\ProviderAdapter;
use Kiln\Providers\Contracts\ProviderGateway;
use Kiln\Providers\Contracts\ProviderType;

/**
 * In-memory ProviderGateway + adapter implementing only the public Providers contracts.
 */
final class FakeProviderGateway implements ProviderGateway
{
    /** @var array<string, CredentialSummary> */
    public array $credentials = [];

    /** @var list<MachineSpec> */
    public array $created = [];

    /** @var list<string> */
    public array $destroyed = [];

    /** @var array<string, string> */
    public array $uploadedKeys = [];

    public ?ProviderException $failCreate = null;

    public ?ProviderException $failDestroy = null;

    public ?string $ipv4 = '198.51.100.20';

    /** Runs inside createServer (e.g. to simulate a deletion racing the provider call). */
    public ?\Closure $beforeCreate = null;

    public function addCredential(string $organizationId, ProviderType $type = ProviderType::Hetzner): CredentialSummary
    {
        $id = '01JCRED'.str_pad((string) count($this->credentials), 19, '0', STR_PAD_LEFT);

        return $this->credentials[$id] = new CredentialSummary($id, $organizationId, 'Main', $type);
    }

    public function credentials(string $organizationId): array
    {
        return array_values(array_filter($this->credentials, fn (CredentialSummary $c) => $c->organizationId === $organizationId));
    }

    public function credential(string $organizationId, string $credentialId): ?CredentialSummary
    {
        $credential = $this->credentials[$credentialId] ?? null;

        return $credential && $credential->organizationId === $organizationId ? $credential : null;
    }

    public function adapter(string $organizationId, string $credentialId): ProviderAdapter
    {
        if (! $this->credential($organizationId, $credentialId)) {
            throw new ProviderException('Unknown credential', 'fake', 404);
        }

        return new FakeProviderAdapter($this);
    }

    public function regions(string $organizationId, string $credentialId): array
    {
        return [];
    }

    public function sizes(string $organizationId, string $credentialId, ?string $region = null): array
    {
        return [];
    }

    public function images(string $organizationId, string $credentialId): array
    {
        return [];
    }
}
