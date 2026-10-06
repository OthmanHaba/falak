<?php

namespace Falak\Databases\Infrastructure;

use Falak\Databases\Application\KeyValue\KeyValueSettings;
use Falak\Databases\Domain\Enums\Compression;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Grant;

/**
 * Builds db.* agent command payloads (contracts/agent-protocol/commands/db.*.schema.json).
 */
final class CommandPayloads
{
    /**
     * @return array<string, mixed>
     */
    public static function create(DatabaseServer $server, Database $database): array
    {
        return array_filter([
            'engine' => $server->engine->protocol(),
            'name' => $database->name,
            'charset' => $server->engine->isMysqlFamily() ? ($database->charset ?: $server->engine->defaultCharset()) : null,
            'collation' => $server->engine->isMysqlFamily() ? ($database->collation ?: $server->engine->defaultCollation()) : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function drop(DatabaseServer $server, Database $database): array
    {
        return ['engine' => $server->engine->protocol(), 'name' => $database->name];
    }

    /**
     * Full desired state of a user: grants only cover databases that exist on the host (active).
     *
     * @return array<string, mixed>
     */
    public static function userPresent(DatabaseServer $server, DatabaseUser $user): array
    {
        $grants = $user->grants()
            ->with('database')
            ->get()
            ->filter(fn (Grant $grant) => $grant->database !== null && $grant->database->status->value === 'active')
            ->sortBy(fn (Grant $grant) => $grant->database->name)
            ->map(fn (Grant $grant) => ['database' => $grant->database->name, 'privileges' => array_values($grant->privileges ?: ['ALL PRIVILEGES'])])
            ->values()
            ->all();

        $payload = [
            'engine' => $server->engine->protocol(),
            'username' => $user->username,
            'password' => $user->password,
            'grants' => $grants,
            'state' => 'present',
        ];

        if ($server->engine->isMysqlFamily()) {
            $payload['host'] = $user->host;
        }

        if (self::remote($server, $user)) {
            $payload['remote'] = true;
        }

        // MySQL users pinned to a local host stay local (like remote()); PostgreSQL users have no host.
        $local = $server->engine->isMysqlFamily() && in_array($user->host, ['localhost', '127.0.0.1', '::1'], true);

        if (! $server->dedicated && $server->container_access && ! $local && ($ranges = (array) config('databases.container_networks', [])) !== []) {
            // Containers on the server (compose, Docker sites, functions) connect from the Docker ranges. Only once
            // container access is on: the agent then makes the engine listen beyond localhost, and the firewall's
            // bridge-only rule for the port (container_ports) exists from that point on (EnableContainerAccess).
            // Agents without db.containers get the field stripped (PayloadCompatibility).
            $payload['containers'] = array_values($ranges);
        }

        return $payload;
    }

    /**
     * Users of a dedicated database server connect from other servers: the agent then makes the engine
     * listen on the network (PostgreSQL / MySQL bind to localhost out of the box). MySQL users pinned to
     * a local host stay local. Reaching the port is still up to the server firewall.
     */
    public static function remote(DatabaseServer $server, DatabaseUser $user): bool
    {
        if (! $server->dedicated) {
            return false;
        }

        return ! ($server->engine->isMysqlFamily() && in_array($user->host, ['localhost', '127.0.0.1', '::1'], true));
    }

    /**
     * @return array<string, mixed>
     */
    public static function userAbsent(DatabaseServer $server, DatabaseUser $user): array
    {
        $payload = ['engine' => $server->engine->protocol(), 'username' => $user->username, 'state' => 'absent'];

        if ($server->engine->isMysqlFamily()) {
            $payload['host'] = $user->host;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function backup(Engine $engine, string $database, Compression $compression, string $uploadUrl): array
    {
        return [
            'engine' => $engine->protocol(),
            'database' => $database,
            'compression' => $compression->value,
            'destination' => ['kind' => 'presigned_url', 'url' => $uploadUrl],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function restore(Engine $engine, string $database, Compression $compression, string $downloadUrl, ?string $sha256, ?int $uncompressedBytes = null): array
    {
        return array_filter([
            'engine' => $engine->protocol(),
            'database' => $database,
            'compression' => $compression->value,
            'source' => ['kind' => 'url', 'url' => $downloadUrl],
            'sha256' => $sha256,
            // Redis / Valkey (feature db.redis.restore_checks): the free space the gunzipped snapshot needs.
            'uncompressed_bytes' => $engine->isKeyValue() && $uncompressedBytes !== null && $uncompressedBytes > 0 ? $uncompressedBytes : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * db.redis.apply: the full desired state of a Redis / Valkey instance. `bind` is 127.0.0.1 plus the private
     * addresses other servers of its environment reach it on, `containers` adds the Docker bridge's address
     * (KeyValueNetwork::desired(); both only for agents with db.redis.network, which strips `containers` otherwise).
     *
     * @param  array{bind?: list<string>, containers?: bool}  $network
     * @return array<string, mixed>
     */
    public static function redisApply(DatabaseServer $server, Database $database, #[\SensitiveParameter] string $password, array $network = []): array
    {
        $settings = KeyValueSettings::of($database);

        return [
            'engine' => $server->engine->protocol(),
            'name' => $database->name,
            'port' => (int) $database->port,
            'password' => $password,
            'bind' => array_values($network['bind'] ?? ['127.0.0.1']),
            ...(($network['containers'] ?? false) ? ['containers' => true] : []),
            'maxmemory_mb' => $settings['maxmemory_mb'],
            'eviction' => $settings['eviction'],
            'persistence' => $settings['persistence'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function redisRemove(DatabaseServer $server, Database $database): array
    {
        return ['engine' => $server->engine->protocol(), 'name' => $database->name];
    }
}
