<?php

namespace Falak\Servers\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Application\Jobs\DestroyServer;
use Falak\Servers\Application\ServerStatusUpdater;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Models\Server;

final class DeleteServer
{
    public function __construct(
        private readonly ServerStatusUpdater $status,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Server $server, bool $destroyAtProvider = true): void
    {
        if ($server->status !== ServerStatus::Deleting) {
            $this->status->set($server, ServerStatus::Deleting, 'Deleting server.');
            $this->audit->record('server.deletion_requested', 'server', $server->id, ['name' => $server->name, 'destroy_at_provider' => $destroyAtProvider], $server->organization_id);
        }

        DestroyServer::dispatch($server->id, $destroyAtProvider);
    }
}
