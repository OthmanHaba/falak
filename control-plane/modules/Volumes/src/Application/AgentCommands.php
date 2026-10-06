<?php

namespace Falak\Volumes\Application;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Data\CommandHandle;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Validation\ValidationException;

/**
 * volume.* commands: "no agent" becomes a user-facing validation error (or null in the background).
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
    public function dispatch(string $serverId, string $type, array $payload, string $key, string $field = 'volume'): CommandHandle
    {
        try {
            return $this->agents->dispatch($serverId, $type, $payload, self::timeout($type), $key);
        } catch (AgentUnavailable) {
            throw ValidationException::withMessages([$field => self::NOT_CONNECTED]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function tryDispatch(string $serverId, string $type, array $payload, string $key): ?CommandHandle
    {
        try {
            return $this->agents->dispatch($serverId, $type, $payload, self::timeout($type), $key);
        } catch (AgentUnavailable) {
            return null;
        }
    }

    /**
     * Run a read-only command and wait for its result (the file browser).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ValidationException when the agent is not connected, failed or did not answer in time
     */
    public function ask(string $serverId, string $type, array $payload, string $field = 'path'): array
    {
        $handle = $this->dispatch($serverId, $type, $payload, "{$type}:".bin2hex(random_bytes(8)), $field);
        $result = $this->agents->await($handle, (int) config('volumes.timeouts.browse_wait', 15));

        if (! $result->isFinished()) {
            $this->agents->cancel($handle);

            throw ValidationException::withMessages([$field => 'The server did not answer in time. Try again.']);
        }

        if (! $result->isSuccessful()) {
            throw ValidationException::withMessages([$field => $result->error ?: 'The server could not read the volume.']);
        }

        return $result->result ?? [];
    }

    public static function timeout(string $type): int
    {
        return max(30, (int) config('volumes.timeouts.'.str_replace('volume.', '', $type), 600));
    }

    /**
     * volume.create (also the shape of restore / clone targets): the volume, its size and labels.
     *
     * @return array<string, mixed>
     */
    public static function createPayload(Volume $volume): array
    {
        return array_filter([
            'volume' => $volume->ref(),
            'size_bytes' => $volume->kind->value === 'sized' ? $volume->size_limit_bytes : null,
            'labels' => self::labels($volume),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Docker labels of a volume Falak creates (Docker volumes only take them; the agent ignores them otherwise).
     *
     * @return array<string, string>
     */
    public static function labels(Volume $volume): array
    {
        return array_slice(['falak.volume.id' => $volume->id, 'falak.volume.name' => $volume->name, ...array_filter(
            (array) $volume->labels,
            fn ($value, $key) => is_string($key) && preg_match('/^[a-z0-9][a-z0-9_.-]{0,62}$/', $key) === 1 && ! str_starts_with($key, 'falak.'),
            ARRAY_FILTER_USE_BOTH,
        )], 0, 32, true);
    }
}
