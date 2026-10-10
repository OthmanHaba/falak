<?php

namespace Falak\Fleet\Contracts\Exceptions;

use RuntimeException;

/**
 * An agent request the control plane won't answer (409): `reason` is a stable code for the agent, the message is for
 * people.
 */
final class AgentRequestRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
