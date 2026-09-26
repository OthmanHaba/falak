<?php

namespace Kiln\Fleet\Contracts;

use Kiln\Fleet\Contracts\Data\CommandHandle;
use Kiln\Fleet\Contracts\Data\CommandOutput;
use Kiln\Fleet\Contracts\Data\CommandResult;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Fleet\Contracts\Exceptions\CommandTimedOut;
use Kiln\Fleet\Contracts\Exceptions\InvalidCommandPayload;
use Kiln\Fleet\Contracts\Exceptions\UnknownCommandType;

/**
 * The only way modules talk to servers (ARCHITECTURE §2.2 rule 6).
 *
 * Commands are queued for the server's enrolled agent, delivered through the agent's long-poll,
 * and report back through events; completion is announced with the CommandFinished / CommandFailed
 * events (listen to those rather than blocking with await() in request handlers).
 */
interface AgentGateway
{
    /**
     * Validate the payload against contracts/agent-protocol/commands/<type>.schema.json and queue it.
     *
     * Dispatching again with the same idempotency key while an earlier command is still pending
     * returns the pending command's handle instead of queueing a duplicate. The agent also answers a
     * repeated key with its cached result, so a key must identify one logical operation instance —
     * never derive it from the desired state alone (A → B → A would be answered from cache).
     *
     * @param  array<string, mixed>|object  $payload
     *
     * @throws UnknownCommandType when no schema exists for $type
     * @throws InvalidCommandPayload
     * @throws AgentUnavailable when the server has no active (non-revoked) agent
     */
    public function dispatch(string $serverId, string $type, array|object $payload, int $timeout = 600, ?string $idempotencyKey = null): CommandHandle;

    /**
     * Block until the command reaches a terminal state (for jobs/CLI; never in HTTP requests).
     *
     * @throws CommandTimedOut when it is still pending after $waitSeconds
     */
    public function await(CommandHandle|string $command, int $waitSeconds = 600): CommandResult;

    public function status(CommandHandle|string $command): CommandResult;

    /**
     * Output lines (stdout/stderr) recorded so far, in order.
     */
    public function output(CommandHandle|string $command, int $afterSeq = -1): CommandOutput;

    /**
     * Cancel a command the agent has not picked up yet. Returns false if it was already delivered.
     */
    public function cancel(CommandHandle|string $command): bool;

    /**
     * Whether a JSON Schema exists for the command type.
     */
    public function supports(string $type): bool;
}
