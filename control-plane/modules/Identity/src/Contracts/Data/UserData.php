<?php

namespace Falak\Identity\Contracts\Data;

final readonly class UserData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
    ) {}
}
