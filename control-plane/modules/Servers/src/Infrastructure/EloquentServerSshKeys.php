<?php

namespace Falak\Servers\Infrastructure;

use Falak\Servers\Application\Actions\AttachSshKey;
use Falak\Servers\Contracts\ServerSshKeys;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Models\SshKey;

final class EloquentServerSshKeys implements ServerSshKeys
{
    public function authorizedKeys(string $serverId): array
    {
        $server = Server::query()->find($serverId);
        /** @var array<string, list<string>> $keys */
        $keys = array_fill_keys(AttachSshKey::UNIX_USERS, []);

        if ($server === null) {
            return $keys;
        }

        /** @var SshKey $key */
        foreach ($server->sshKeys()->get() as $key) {
            $user = (string) $key->getRelationValue('pivot')?->getAttribute('unix_user');

            if (isset($keys[$user])) {
                $keys[$user][] = $key->public_key;
            }
        }

        return $keys;
    }

    public function sshPort(string $serverId): int
    {
        return (int) (Server::query()->whereKey($serverId)->value('ssh_port') ?? 22);
    }
}
