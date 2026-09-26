<?php

namespace Tests\Support;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\CommandStatus;
use Kiln\Fleet\Contracts\Data\CommandHandle;
use Kiln\Fleet\Contracts\Data\CommandOutput;
use Kiln\Fleet\Contracts\Data\CommandResult;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Fleet\Contracts\Exceptions\InvalidCommandPayload;
use Kiln\Fleet\Contracts\Exceptions\UnknownCommandType;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Fleet\Events\CommandOutputReceived;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Servers\Contracts\ServerDirectory;
use PHPUnit\Framework\Assert;

/**
 * In-memory AgentGateway for module tests. Payloads are validated against the real agent-protocol
 * schemas; outcomes are simulated by firing the public Fleet events (CommandFinished / CommandFailed /
 * CommandOutputReceived) exactly like the real gateway does.
 *
 * Usage: `$agents = FakeAgentGateway::install();`
 */
final class FakeAgentGateway implements AgentGateway
{
    /** @var array<string, array{handle: CommandHandle, payload: array<string, mixed>, timeout: int, status: CommandStatus, result: ?array<string, mixed>, exit_code: ?int, error: ?string, output: list<array{seq: int, stream: string, data: string, at: string}>}> */
    public array $commands = [];

    /** @var array<string, true> server ids without a connected agent */
    private array $unavailable = [];

    /** @var array<string, string> server id => organization id (for emitted events) */
    private array $organizations = [];

    public function __construct(private readonly ProtocolSchemas $schemas) {}

    public static function install(): self
    {
        $fake = new self(app(ProtocolSchemas::class));
        app()->instance(AgentGateway::class, $fake);

        return $fake;
    }

    public function unavailable(string $serverId): self
    {
        $this->unavailable[$serverId] = true;

        return $this;
    }

    public function available(string $serverId): self
    {
        unset($this->unavailable[$serverId]);

        return $this;
    }

    public function organization(string $serverId, string $organizationId): self
    {
        $this->organizations[$serverId] = $organizationId;

        return $this;
    }

    public function dispatch(string $serverId, string $type, array|object $payload, int $timeout = 600, ?string $idempotencyKey = null): CommandHandle
    {
        if (! $this->schemas->hasCommand($type)) {
            throw UnknownCommandType::named($type);
        }

        $document = ProtocolSchemas::toJson($payload);
        $errors = $this->schemas->validateCommand($type, $document);

        if ($errors !== []) {
            throw new InvalidCommandPayload($type, $errors);
        }

        if (isset($this->unavailable[$serverId])) {
            throw AgentUnavailable::forServer($serverId);
        }

        if ($idempotencyKey !== null) {
            foreach ($this->commands as $command) {
                if ($command['handle']->serverId === $serverId && $command['handle']->idempotencyKey === $idempotencyKey && ! $command['status']->isTerminal()) {
                    return $command['handle'];
                }
            }
        }

        $id = (string) Str::ulid();
        $handle = new CommandHandle($id, $serverId, 'agent-'.$serverId, $type, $idempotencyKey ?? $id);

        $this->commands[$id] = [
            'handle' => $handle,
            'payload' => json_decode((string) json_encode($document, JSON_UNESCAPED_SLASHES), true),
            'timeout' => $timeout,
            'status' => CommandStatus::Queued,
            'result' => null,
            'exit_code' => null,
            'error' => null,
            'output' => [],
        ];

        return $handle;
    }

    public function await(CommandHandle|string $command, int $waitSeconds = 600): CommandResult
    {
        return $this->status($command);
    }

    public function status(CommandHandle|string $command): CommandResult
    {
        $c = $this->commands[$this->id($command)];

        return new CommandResult(
            $c['handle']->id,
            $c['handle']->serverId,
            $c['handle']->type,
            $c['status'],
            $c['exit_code'],
            $c['result'],
            $c['error'],
            new DateTimeImmutable,
            $c['status']->isTerminal() ? new DateTimeImmutable : null,
        );
    }

    public function output(CommandHandle|string $command, int $afterSeq = -1): CommandOutput
    {
        $id = $this->id($command);
        $lines = array_values(array_filter($this->commands[$id]['output'] ?? [], fn (array $line) => $line['seq'] > $afterSeq));

        return new CommandOutput($id, $lines, $lines === [] ? $afterSeq : $lines[array_key_last($lines)]['seq']);
    }

    public function cancel(CommandHandle|string $command): bool
    {
        $id = $this->id($command);

        if (($this->commands[$id]['status'] ?? null) !== CommandStatus::Queued) {
            return false;
        }

        $this->commands[$id]['status'] = CommandStatus::Cancelled;

        return true;
    }

    public function supports(string $type): bool
    {
        return $this->schemas->hasCommand($type);
    }

    // ---- simulation helpers -------------------------------------------------------------------

    /**
     * @return list<array{handle: CommandHandle, payload: array<string, mixed>, timeout: int}>
     */
    public function dispatched(?string $type = null, ?string $serverId = null): array
    {
        return array_values(array_filter(
            $this->commands,
            fn (array $c) => ($type === null || $c['handle']->type === $type) && ($serverId === null || $c['handle']->serverId === $serverId),
        ));
    }

    /**
     * @return array{handle: CommandHandle, payload: array<string, mixed>, timeout: int}
     */
    public function last(?string $type = null, ?string $serverId = null): array
    {
        $all = $this->dispatched($type, $serverId);
        Assert::assertNotEmpty($all, 'No ['.($type ?? 'any').'] command was dispatched'.($serverId ? " to [{$serverId}]" : '').'.');

        return $all[array_key_last($all)];
    }

    public function assertNothingDispatched(?string $type = null): void
    {
        Assert::assertSame([], array_map(fn ($c) => $c['handle']->type, $this->dispatched($type)), 'Unexpected commands were dispatched.');
    }

    public function started(CommandHandle|string $command): void
    {
        $id = $this->id($command);
        $this->commands[$id]['status'] = CommandStatus::Running;
        CommandOutputReceived::dispatch($id, $this->commands[$id]['handle']->serverId, 'running', [
            ['seq' => 0, 'kind' => 'started', 'stream' => null, 'data' => null, 'progress' => null, 'at' => now()->toIso8601ZuluString()],
        ]);
    }

    /**
     * Emit output events (as the real ingestion does) and record them for output().
     *
     * @param  list<string>|string  $chunks
     */
    public function emit(CommandHandle|string $command, array|string $chunks, string $stream = 'stdout', ?string $at = null): void
    {
        $id = $this->id($command);
        $this->commands[$id]['status'] = CommandStatus::Running;
        $events = [];

        foreach ((array) $chunks as $data) {
            $seq = count($this->commands[$id]['output']) + 1;
            $line = ['seq' => $seq, 'stream' => $stream, 'data' => $data, 'at' => $at ?? now()->toIso8601ZuluString()];
            $this->commands[$id]['output'][] = $line;
            $events[] = ['seq' => $seq, 'kind' => 'output', 'stream' => $stream, 'data' => $data, 'progress' => null, 'at' => $line['at']];
        }

        CommandOutputReceived::dispatch($id, $this->commands[$id]['handle']->serverId, 'running', $events);
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function succeed(CommandHandle|string $command, ?array $result = null, int $exitCode = 0): void
    {
        $id = $this->id($command);
        $c = &$this->commands[$id];
        $c['status'] = CommandStatus::Succeeded;
        $c['result'] = $result;
        $c['exit_code'] = $exitCode;

        CommandFinished::dispatch($id, $this->organizationOf($c['handle']->serverId), $c['handle']->serverId, $c['handle']->type, $c['handle']->idempotencyKey, $exitCode, $result);
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function fail(CommandHandle|string $command, ?string $error = 'boom', ?int $exitCode = 1, string $status = 'failed', ?array $result = null): void
    {
        $id = $this->id($command);
        $c = &$this->commands[$id];
        $c['status'] = CommandStatus::from($status);
        $c['error'] = $error;
        $c['exit_code'] = $exitCode;
        $c['result'] = $result;

        CommandFailed::dispatch($id, $this->organizationOf($c['handle']->serverId), $c['handle']->serverId, $c['handle']->type, $c['handle']->idempotencyKey, $status, $error, $exitCode, $result);
    }

    private function organizationOf(string $serverId): string
    {
        return $this->organizations[$serverId] ?? app(ServerDirectory::class)->find($serverId)?->organizationId ?? '';
    }

    private function id(CommandHandle|string $command): string
    {
        return $command instanceof CommandHandle ? $command->id : $command;
    }
}
