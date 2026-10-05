<?php

namespace Falak\Servers\Application\Actions;

use Illuminate\Validation\ValidationException;
use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Models\SshKey;

final class AttachSshKey
{
    public const UNIX_USERS = ['falak', 'root'];

    public function __construct(
        private readonly SyncServerSshKeys $sync,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Server $server, SshKey $key, string $unixUser): void
    {
        if ($key->organization_id !== $server->organization_id) {
            throw ValidationException::withMessages(['ssh_key_id' => 'Unknown SSH key.']);
        }

        if (! in_array($unixUser, self::UNIX_USERS, true)) {
            throw ValidationException::withMessages(['unix_user' => 'Keys can be installed for the falak or root user.']);
        }

        if ($server->sshKeys()->wherePivot('unix_user', $unixUser)->whereKey($key->id)->exists()) {
            return;
        }

        $server->sshKeys()->attach($key->id, ['unix_user' => $unixUser]);
        ($this->sync)($server);

        $this->audit->record('server.ssh_key_attached', 'server', $server->id, ['ssh_key' => $key->name, 'unix_user' => $unixUser], $server->organization_id);
    }
}
