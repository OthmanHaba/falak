<?php

namespace Kiln\Sites\Tests\Support;

use Closure;
use Illuminate\Support\Str;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\CommandStatus;
use Kiln\Fleet\Contracts\Data\CommandHandle;
use Kiln\Fleet\Contracts\Data\CommandOutput;
use Kiln\Fleet\Contracts\Data\CommandResult;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Fleet\Contracts\Exceptions\InvalidCommandPayload;

/**
 * AgentGateway double that records dispatches and validates each payload against the protocol schema
 * (validator injected from the non-namespaced test helpers).
 */
final class RecordingAgentGateway implements AgentGateway
{
    /** @var list<array{id: string, server_id: string, type: string, payload: array<string, mixed>, timeout: int, key: string}> */
    public array $dispatched = [];

    /** @var list<string> servers without an agent */
    public array $offline = [];

    /**
     * @param  Closure(string, mixed): array<string, list<string>>  $validator
     */
    public function __construct(private readonly Closure $validator) {}

    public function dispatch(string $serverId, string $type, array|object $payload, int $timeout = 600, ?string $idempotencyKey = null): CommandHandle
    {
        if (in_array($serverId, $this->offline, true)) {
            throw AgentUnavailable::forServer($serverId);
        }

        $errors = ($this->validator)($type, $payload);

        if ($errors !== []) {
            throw new InvalidCommandPayload($type, $errors);
        }

        $id = (string) Str::ulid();
        $key = $idempotencyKey ?? $id;
        $this->dispatched[] = ['id' => $id, 'server_id' => $serverId, 'type' => $type, 'payload' => json_decode((string) json_encode($payload), true), 'timeout' => $timeout, 'key' => $key];

        return new CommandHandle($id, $serverId, 'agent-'.$serverId, $type, $key);
    }

    /**
     * @return list<array{id: string, server_id: string, type: string, payload: array<string, mixed>, timeout: int, key: string}>
     */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->dispatched, fn (array $command) => $command['type'] === $type));
    }

    /**
     * @return array{id: string, server_id: string, type: string, payload: array<string, mixed>, timeout: int, key: string}|null
     */
    public function last(?string $type = null): ?array
    {
        $commands = $type === null ? $this->dispatched : $this->ofType($type);

        return $commands === [] ? null : $commands[array_key_last($commands)];
    }

    public function await(CommandHandle|string $command, int $waitSeconds = 600): CommandResult
    {
        return $this->status($command);
    }

    public function status(CommandHandle|string $command): CommandResult
    {
        $id = $command instanceof CommandHandle ? $command->id : $command;
        $found = collect($this->dispatched)->firstWhere('id', $id);

        return new CommandResult($id, (string) ($found['server_id'] ?? ''), (string) ($found['type'] ?? ''), CommandStatus::Queued);
    }

    public function output(CommandHandle|string $command, int $afterSeq = -1): CommandOutput
    {
        return new CommandOutput($command instanceof CommandHandle ? $command->id : $command, [], $afterSeq);
    }

    public function cancel(CommandHandle|string $command): bool
    {
        return true;
    }

    public function supports(string $type): bool
    {
        return true;
    }
}
