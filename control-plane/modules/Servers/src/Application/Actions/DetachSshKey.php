<?php

namespace Kiln\Servers\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Domain\Models\SshKey;

final class DetachSshKey
{
    public function __construct(
        private readonly SyncServerSshKeys $sync,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Server $server, SshKey $key, ?string $unixUser = null): void
    {
        $query = $server->sshKeys()->newPivotStatement()->where('server_id', $server->id)->where('ssh_key_id', $key->id);

        if ($unixUser !== null) {
            $query->where('unix_user', $unixUser);
        }

        $query->delete();
        ($this->sync)($server);

        $this->audit->record('server.ssh_key_detached', 'server', $server->id, ['ssh_key' => $key->name, 'unix_user' => $unixUser], $server->organization_id);
    }
}
