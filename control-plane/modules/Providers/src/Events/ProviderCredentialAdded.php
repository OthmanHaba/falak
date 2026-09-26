<?php

namespace Kiln\Providers\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ProviderCredentialAdded
{
    use Dispatchable;

    public function __construct(
        public string $organizationId,
        public string $credentialId,
        public string $provider,
    ) {}
}
