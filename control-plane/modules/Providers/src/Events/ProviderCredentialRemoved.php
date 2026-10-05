<?php

namespace Falak\Providers\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ProviderCredentialRemoved
{
    use Dispatchable;

    public function __construct(
        public string $organizationId,
        public string $credentialId,
        public string $provider,
    ) {}
}
