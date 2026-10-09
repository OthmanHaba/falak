<?php

namespace Falak\Servers\Contracts;

/**
 * What Falak lets log in to a server over SSH (Security's baseline report compares the server against it).
 */
interface ServerSshKeys
{
    /**
     * The public keys Falak installs, per unix user (the falak and root users), as OpenSSH lines.
     *
     * @return array<string, list<string>>
     */
    public function authorizedKeys(string $serverId): array;

    public function sshPort(string $serverId): int;
}
