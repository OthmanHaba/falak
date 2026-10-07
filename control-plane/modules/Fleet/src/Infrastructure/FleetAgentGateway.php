<?php

namespace Falak\Fleet\Infrastructure;

use Falak\Fleet\Application\Actions\QueueCommand;
use Falak\Fleet\Application\CommandLifecycle;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Contracts\Data\CommandHandle;
use Falak\Fleet\Contracts\Data\CommandOutput;
use Falak\Fleet\Contracts\Data\CommandResult;
use Falak\Fleet\Contracts\Exceptions\CommandTimedOut;
use Falak\Fleet\Domain\Models\Command;
use Falak\Fleet\Domain\Models\CommandEvent;
use Illuminate\Support\Arr;

final class FleetAgentGateway implements AgentGateway
{
    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private readonly QueueCommand $queue,
        private readonly CommandLifecycle $lifecycle,
        private readonly ProtocolSchemas $schemas,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? fn (int $microseconds) => usleep($microseconds);
    }

    public function dispatch(string $serverId, string $type, array|object $payload, int $timeout = 600, ?string $idempotencyKey = null): CommandHandle
    {
        return ($this->queue)($serverId, $type, $payload, $timeout, $idempotencyKey)->toHandle();
    }

    public function await(CommandHandle|string $command, int $waitSeconds = 600): CommandResult
    {
        $id = $this->id($command);
        $deadline = microtime(true) + $waitSeconds;

        while (true) {
            $result = $this->status($id);

            if ($result->isFinished()) {
                return $result;
            }

            if (microtime(true) >= $deadline) {
                throw CommandTimedOut::waiting($id, $waitSeconds);
            }

            ($this->sleeper)(500_000);
        }
    }

    public function status(CommandHandle|string $command): CommandResult
    {
        return Command::query()->findOrFail($this->id($command))->toResult();
    }

    public function output(CommandHandle|string $command, int $afterSeq = -1): CommandOutput
    {
        $id = $this->id($command);

        $lines = CommandEvent::query()
            ->where('command_id', $id)
            ->where('kind', 'output')
            ->where('seq', '>', $afterSeq)
            ->orderBy('seq')
            ->get(['seq', 'stream', 'data', 'at'])
            ->map(fn (CommandEvent $event) => [
                'seq' => $event->seq,
                'stream' => $event->stream ?? 'stdout',
                'data' => (string) $event->data,
                'at' => $event->at->toIso8601String(),
            ])
            ->values()
            ->all();

        $last = $lines === [] ? $afterSeq : $lines[array_key_last($lines)]['seq'];

        return new CommandOutput($id, $lines, $last);
    }

    public function cancel(CommandHandle|string $command): bool
    {
        $model = Command::query()->findOrFail($this->id($command));

        if ($model->status !== CommandStatus::Queued) {
            return $model->status === CommandStatus::Cancelled;
        }

        $this->lifecycle->fail($model, CommandStatus::Cancelled, 'Cancelled before delivery.');

        return true;
    }

    public function supports(string $type): bool
    {
        return $this->schemas->hasCommand($type);
    }

    public function forgetSecrets(CommandHandle|string $command, array $paths): bool
    {
        $model = Command::query()->find($this->id($command));

        if ($model === null || ! $model->status->isTerminal()) {
            return false;
        }

        $payload = json_decode($model->payload, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            return true;
        }

        $changed = false;

        foreach ($paths as $path) {
            if (Arr::has($payload, $path) && Arr::get($payload, $path) !== '[forgotten]') {
                Arr::set($payload, $path, '[forgotten]');
                $changed = true;
            }
        }

        if ($changed) {
            $model->forceFill(['payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)])->save();
        }

        return true;
    }

    private function id(CommandHandle|string $command): string
    {
        return $command instanceof CommandHandle ? $command->id : $command;
    }
}
