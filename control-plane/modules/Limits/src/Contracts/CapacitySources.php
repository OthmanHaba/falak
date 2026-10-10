<?php

namespace Falak\Limits\Contracts;

/**
 * Registry of {@see CapacitySource}s (modules register theirs while booting).
 */
interface CapacitySources
{
    /** @param  class-string<CapacitySource>  $source  resolved from the container when the view is built */
    public function register(string $source): void;

    /** @return list<CapacitySource> */
    public function all(): array;
}
