<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Infrastructure\CommandPayloads;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Converges an instance's container with its row (db.instance.update): limits, settings, image, network, published
 * addresses. The agent recreates the container only when its spec changed; the data stays on the volume.
 */
final class ApplyInstance
{
    public function __construct(private readonly AgentCommands $commands) {}

    /**
     * @param  bool  $background  record "agent not connected" on the instance instead of throwing
     *
     * @throws ValidationException
     */
    public function __invoke(DatabaseInstance $instance, bool $background = false): void
    {
        $payload = CommandPayloads::instance($instance);
        $key = "db.instance.update:{$instance->id}:".Str::ulid();
        $timeout = (int) config('databases.timeouts.instance', 1800);

        $handle = $background
            ? $this->commands->tryDispatch($instance->server_id, 'db.instance.update', $payload, $timeout, $key)
            : $this->commands->dispatch($instance->server_id, 'db.instance.update', $payload, $timeout, $key, 'instance');

        $instance->forceFill($handle !== null
            ? ['command_id' => $handle->id, 'status_message' => null]
            : ['status_message' => 'Not applied: '.AgentCommands::NOT_CONNECTED])->save();
    }
}
