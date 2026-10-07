<?php

namespace Falak\Edge\Tests\Support;

use Closure;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Contracts\Data\CommandHandle;
use Falak\Fleet\Contracts\Data\CommandOutput;
use Falak\Fleet\Contracts\Data\CommandResult;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Fleet\Contracts\Exceptions\InvalidCommandPayload;
use Illuminate\Support\Str;

/**
 * Records dispatched commands; every payload is validated against its JSON Schema like the real gateway.
 */
final class RecordingAgentGateway implements AgentGateway
{
    /** @var list<array{handle: CommandHandle, type: string, payload: array<string, mixed>, timeout: int}> */
    public array $dispatched = [];

    /** @var list<string> */
    public array $offline = [];

    /**
     * @param  Closure(string, array<string, mixed>): array<string, list<string>>  $validate
     */
    public function __construct(private readonly Closure $validate) {}

    public function dispatch(string $serverId, string $type, array|object $payload, int $timeout = 600, ?string $idempotencyKey = null): CommandHandle
    {
        if (in_array($serverId, $this->offline, true)) {
            throw new AgentUnavailable("Server {$serverId} has no agent.");
        }

        $payload = (array) $payload;
        $errors = ($this->validate)($type, $payload);

        if ($errors !== []) {
            throw new InvalidCommandPayload($type, $errors);
        }

        $handle = new CommandHandle((string) Str::ulid(), $serverId, 'agent-'.$serverId, $type, $idempotencyKey ?? (string) Str::ulid());
        $this->dispatched[] = ['handle' => $handle, 'type' => $type, 'payload' => $payload, 'timeout' => $timeout];

        return $handle;
    }

    /**
     * @return list<array{handle: CommandHandle, type: string, payload: array<string, mixed>, timeout: int}>
     */
    public function ofType(string $type, ?string $serverId = null): array
    {
        return array_values(array_filter($this->dispatched, fn (array $c) => $c['type'] === $type && ($serverId === null || $c['handle']->serverId === $serverId)));
    }

    public function await(CommandHandle|string $command, int $waitSeconds = 600): CommandResult
    {
        return $this->status($command);
    }

    public function status(CommandHandle|string $command): CommandResult
    {
        $id = $command instanceof CommandHandle ? $command->id : $command;

        return new CommandResult($id, '', '', CommandStatus::Queued);
    }

    public function output(CommandHandle|string $command, int $afterSeq = -1): CommandOutput
    {
        return new CommandOutput($command instanceof CommandHandle ? $command->id : $command, [], $afterSeq);
    }

    public function cancel(CommandHandle|string $command): bool
    {
        return false;
    }

    public function supports(string $type): bool
    {
        return true;
    }

    public function forgetSecrets(CommandHandle|string $command, array $paths): bool
    {
        return true;
    }
}
