<?php

namespace Kiln\Templates\Tests\Support;

use Kiln\Templates\Application\Import\HostResolver;

final class FakeHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $hosts
     */
    public function __construct(public array $hosts = []) {}

    public function resolve(string $host): array
    {
        return $this->hosts[$host] ?? [];
    }
}
