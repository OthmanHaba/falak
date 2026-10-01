<?php

namespace Kiln\Databases\Contracts\Data;

/**
 * Who connects to a database through a reference: the host it gets depends on where it runs.
 */
final readonly class DatabaseConsumer
{
    /**
     * @param  list<string>  $serverIds  servers the consumer runs on
     * @param  bool  $containerized  runs in a container (Docker, compose, function): 127.0.0.1 is the container itself
     */
    public function __construct(
        public string $name,
        public array $serverIds,
        public bool $containerized,
    ) {}
}
