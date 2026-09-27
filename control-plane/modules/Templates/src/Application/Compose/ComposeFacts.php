<?php

namespace Kiln\Templates\Application\Compose;

/**
 * What the compose runtime says about a compose file: its services, their exposed ports, named volumes and
 * policy violations (docs/COMPOSE_TEMPLATES.md §1.3). Produced from `Sites\Contracts\ComposeInspector`.
 */
final readonly class ComposeFacts
{
    /**
     * @param  array<string, list<int>>  $services  service name => exposed container ports
     * @param  list<string>  $volumes  named volumes
     * @param  list<string>  $violations  policy / structure problems, human readable
     */
    public function __construct(
        public array $services,
        public array $volumes,
        public array $violations,
    ) {}
}
