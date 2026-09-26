<?php

namespace Kiln\Identity\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class OrganizationDeleted
{
    use Dispatchable;

    public function __construct(public string $organizationId) {}
}
