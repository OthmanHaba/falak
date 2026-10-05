<?php

namespace Falak\Servers\Contracts\Data;

final readonly class PhpSettings
{
    /**
     * @param  array<string, string|int|bool>  $ini  php.ini overrides
     * @param  array{pm: string, max_children: int, start_servers: int, min_spare_servers: int, max_spare_servers: int, max_requests: int}  $fpm  pool defaults
     */
    public function __construct(
        public string $version,
        public array $ini,
        public array $fpm,
        public bool $isDefault,
    ) {}
}
