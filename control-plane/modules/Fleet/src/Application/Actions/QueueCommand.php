<?php

namespace Kiln\Fleet\Application\Actions;

use Illuminate\Support\Str;
use Kiln\Fleet\Contracts\AgentStatus;
use Kiln\Fleet\Contracts\CommandStatus;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Fleet\Contracts\Exceptions\InvalidCommandPayload;
use Kiln\Fleet\Contracts\Exceptions\UnknownCommandType;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Domain\Models\Command;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Fleet\Infrastructure\Signals\CommandSignal;

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

        $this->signal->notify($agent->id);

        return $command;
    }
}
