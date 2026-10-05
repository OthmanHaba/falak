<?php

namespace Falak\Identity\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class MemberRemoved
{
    use Dispatchable;

    public function __construct(public string $organizationId, public string $userId) {}
}
