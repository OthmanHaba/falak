<?php

namespace Falak\Fleet\Infrastructure;

use Falak\Fleet\Contracts\AgentRequests;
use InvalidArgumentException;

final class AgentRequestRegistry implements AgentRequests
{
    /** @var array<string, class-string> */
    private array $handlers = [];

    public function register(string $type, string $handler): void
    {
        if (preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $type) !== 1) {
            throw new InvalidArgumentException("Invalid agent request type [{$type}].");
        }

        $this->handlers[$type] = $handler;
    }

    public function handler(string $type): ?string
    {
        return $this->handlers[$type] ?? null;
    }
}
