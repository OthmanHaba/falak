<?php

namespace Falak\Databases\Application;

use Illuminate\Validation\ValidationException;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Data\CommandHandle;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;

/**
 * Thin wrapper over the AgentGateway that turns "no agent" into a user-facing validation error.
 */
final class AgentCommands
{
    public const NOT_CONNECTED = 'The server agent is not connected.';

    public function __construct(private readonly AgentGateway $agents) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException when the server has no agent
     */
    public function dispatch(string $serverId, string $type, array $payload, int $timeout, string $key, string $field = 'server'): CommandHandle
    {
        try {
            return $this->agents->dispatch($serverId, $type, $payload, $timeout, $key);
        } catch (AgentUnavailable) {
            throw ValidationException::withMessages([$field => self::NOT_CONNECTED]);
        }
    }

    /**
     * Background variant: returns null when the server has no agent.
     *
     * @param  array<string, mixed>  $payload
     */
    public function tryDispatch(string $serverId, string $type, array $payload, int $timeout, string $key): ?CommandHandle
    {
        try {
            return $this->agents->dispatch($serverId, $type, $payload, $timeout, $key);
        } catch (AgentUnavailable) {
            return null;
        }
    }
}
