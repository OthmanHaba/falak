<?php

namespace Kiln\Edge\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class DomainRemoved
{
    use Dispatchable;

    public function __construct(
        public string $domainId,
        public string $siteId,
        public string $organizationId,
        public string $name,
    ) {}
}
