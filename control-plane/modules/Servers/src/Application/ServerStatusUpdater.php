<?php

namespace Kiln\Servers\Application;

use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Events\ServerUpdated;

final class ServerStatusUpdater
{
    /**
     * @param  array<string, mixed>  $attributes  additional columns to persist in the same write
     */
    public function set(Server $server, ServerStatus $status, ?string $message = null, array $attributes = []): void
    {
        $server->forceFill([...$attributes, 'status' => $status, 'status_message' => $message !== null ? mb_substr($message, 0, 1000) : null])->save();

        ServerUpdated::dispatch($server->id, $status->value, $server->status_message, $server->provision_command_id);
    }
}
