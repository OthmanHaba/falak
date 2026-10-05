<?php

namespace Falak\Servers\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Domain\Models\Server;

final class RenameServer
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Server $server, string $name): void
    {
        $from = $server->name;
        $server->forceFill(['name' => $name])->save();

        $this->audit->record('server.renamed', 'server', $server->id, ['from' => $from, 'to' => $name], $server->organization_id);
    }
}
