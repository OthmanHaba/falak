<?php

namespace Falak\Identity\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class UserRegistered
{
    use Dispatchable;

    public function __construct(public string $userId, public string $email) {}
}
