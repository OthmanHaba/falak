<?php

namespace Falak\Servers\Application;

use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Events\ServerUpdated;

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
