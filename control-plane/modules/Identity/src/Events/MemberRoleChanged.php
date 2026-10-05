<?php

namespace Falak\Identity\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class MemberRoleChanged
{
    use Dispatchable;

    public function __construct(public string $organizationId, public string $userId, public string $from, public string $to) {}
}
