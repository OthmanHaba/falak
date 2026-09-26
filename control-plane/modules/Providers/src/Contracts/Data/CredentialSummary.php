<?php

namespace Kiln\Providers\Contracts\Data;

use Kiln\Providers\Contracts\ProviderType;

final readonly class CredentialSummary
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $name,
        public ProviderType $provider,
    ) {}
}
