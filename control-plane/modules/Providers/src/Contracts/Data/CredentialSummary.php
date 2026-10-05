<?php

namespace Falak\Providers\Contracts\Data;

use Falak\Providers\Contracts\ProviderType;

final readonly class CredentialSummary
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $name,
        public ProviderType $provider,
    ) {}
}
