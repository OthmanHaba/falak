<?php

namespace Falak\Fleet\Application\Actions;

use Falak\Fleet\Application\PayloadCompatibility;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Fleet\Contracts\Exceptions\InvalidCommandPayload;
use Falak\Fleet\Contracts\Exceptions\UnknownCommandType;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Domain\Models\Command;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Fleet\Infrastructure\Signals\CommandSignal;
use Illuminate\Support\Str;

final class QueueCommand
{
    public const MAX_TIMEOUT = 3600;

    public function __construct(
        private readonly ProtocolSchemas $schemas,
        private readonly CommandSignal $signal,
    ) {}

    /**
     * @param  array<string, mixed>|object  $payload
     */
    public function __invoke(string $serverId, string $type, array|object $payload, int $timeout, ?string $idempotencyKey): Command
    {
        if (! $this->schemas->hasCommand($type)) {
            throw UnknownCommandType::named($type);
        }

        $document = ProtocolSchemas::toJson($payload);

        if (! is_object($document)) {
            throw new InvalidCommandPayload($type, ['/' => ['The payload must be a JSON object.']]);
        }

        $errors = $this->schemas->validateCommand($type, $document);

        if ($errors !== []) {
            throw new InvalidCommandPayload($type, $errors);
        }

        $agent = Agent::query()
            ->where('server_id', $serverId)
            ->where('status', '!=', AgentStatus::Revoked)
            ->latest('enrolled_at')
            ->first() ?? throw AgentUnavailable::forServer($serverId);

        $timeout = max(1, min(self::MAX_TIMEOUT, $timeout));
        $document = PayloadCompatibility::adapt($type, $document, $agent->features());

        if ($idempotencyKey !== null) {
            $pending = $agent->commands()
                ->where('idempotency_key', $idempotencyKey)
                ->whereIn('status', CommandStatus::pending())
                ->first();

            if ($pending) {
                return $pending;
            }
        }

        $id = (string) Str::ulid();

        $command = $agent->commands()->create([
            'id' => $id,
            'organization_id' => $agent->organization_id,
            'server_id' => $serverId,
            'type' => $type,
            'payload' => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'timeout_s' => $timeout,
            'idempotency_key' => $idempotencyKey ?? $id,
            'status' => CommandStatus::Queued,
            'queued_at' => now(),
        ]);

        // Wake the agent's long-poll only once the row is visible to it. Callers often queue inside a
        // transaction (e.g. the deployment orchestrator): a wake-up sent before COMMIT is consumed by a poll
        // that cannot see the command yet, which then sleeps out the rest of its wait window.
        $agentId = $agent->id;
        $command->getConnection()->afterCommit(fn () => $this->signal->notify($agentId));

        return $command;
    }
}
