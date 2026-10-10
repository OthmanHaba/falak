<?php

namespace Falak\Limits\Infrastructure;

use Falak\Limits\Contracts\CapacitySource;
use Falak\Limits\Contracts\CapacitySources;
use Illuminate\Contracts\Container\Container;

final class InMemoryCapacitySources implements CapacitySources
{
    /** @var list<class-string<CapacitySource>> */
    private array $sources = [];

    public function __construct(private readonly Container $container) {}

    public function register(string $source): void
    {
        if (! in_array($source, $this->sources, true)) {
            $this->sources[] = $source;
        }
    }

    public function all(): array
    {
        return array_map(fn (string $source) => $this->container->make($source), $this->sources);
    }
}
