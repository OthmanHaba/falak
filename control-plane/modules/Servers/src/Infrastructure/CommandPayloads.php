<?php

namespace Falak\Servers\Infrastructure;

use Falak\Servers\Domain\Models\PhpVersion;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Models\SshKey;

/**
 * Payload builders for single-purpose agent commands owned by Servers.
 */
final class CommandPayloads
{
    /**
     * @param  list<string>  $extensions
     * @return array<string, mixed> runtime.php.install
     */
    public static function phpInstall(Server $server, string $version, array $extensions, bool $cliDefault): array
    {
        return [
            'version' => $version,
            'extensions' => array_values($extensions),
            'fpm' => $server->stack->phpRuntime === 'fpm',
            'cli_default' => $cliDefault,
        ];
    }

    /**
     * @return array<string, mixed> runtime.php.configure
     */
    public static function phpConfigure(PhpVersion $php): array
    {
        return ['version' => $php->version, 'sapi' => 'all', 'ini' => (object) $php->ini];
    }

    /**
     * @param  iterable<SshKey>  $keys
     * @return array<string, mixed> system.ssh_key.sync
     */
    public static function sshKeySync(string $unixUser, iterable $keys): array
    {
        $entries = [];

        foreach ($keys as $key) {
            $entries[] = ['id' => $key->id, 'name' => $key->name, 'public_key' => $key->public_key];
        }

        // Non-exclusive: only the "# falak-managed" block is converged, so provider-installed keys survive.
        return ['user' => $unixUser, 'keys' => $entries, 'exclusive' => false];
    }
}
