<?php

namespace Falak\Servers\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Models\SshKey;

/**
 * Deletes a key and removes it from every server it was synced to.
 */
final class DeleteSshKey
{
    public function __construct(
        private readonly SyncServerSshKeys $sync,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(SshKey $key): void
    {
        $servers = $key->servers()->get();
        $key->delete();

        $servers->each(fn (Server $server) => ($this->sync)($server));

        $this->audit->record('ssh_key.deleted', 'ssh_key', $key->id, ['name' => $key->name, 'fingerprint' => $key->fingerprint, 'servers' => $servers->count()], $key->organization_id);
    }
}
