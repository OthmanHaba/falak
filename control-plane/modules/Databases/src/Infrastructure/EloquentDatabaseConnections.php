<?php

namespace Kiln\Databases\Infrastructure;

use Kiln\Databases\Application\KeyValue\ApplyKeyValueInstance;
use Kiln\Databases\Application\KeyValue\KeyValueNetwork;
use Kiln\Databases\Contracts\Data\DatabaseConsumer;
use Kiln\Databases\Contracts\DatabaseConnections;
use Kiln\Databases\Domain\Enums\Engine;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Network\Contracts\PrivateNetwork;
use Kiln\Servers\Contracts\ServerDirectory;

final class EloquentDatabaseConnections implements DatabaseConnections
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly PrivateNetwork $network,
        private readonly KeyValueNetwork $keyValueNetwork,
    ) {}

    public function keysFor(string $engine): array
    {
        return Engine::tryFrom($engine)?->isKeyValue() ? self::REDIS_KEYS : self::KEYS;
    }

    public function hostKeysFor(string $engine): array
    {
        return Engine::tryFrom($engine)?->isKeyValue() ? self::REDIS_HOST_KEYS : self::HOST_KEYS;
    }

    public function variables(string $databaseId, ?DatabaseConsumer $consumer = null): array
    {
        $database = Database::query()->with('databaseServer')->find($databaseId);

        if ($database === null) {
            return [];
        }

        $engine = $database->databaseServer->engine;

        if ($engine->isKeyValue()) {
            return $this->keyValue($database, $consumer);
        }

        // Engines on app/worker servers are for that server only (see CommandPayloads::remote): native sites reach
        // them on 127.0.0.1, its containers on the server's own address (127.0.0.1 is the container itself; the
        // firewall lets the Docker bridges in). Only dedicated database servers listen on the network.
        $host = match (true) {
            $database->databaseServer->dedicated, $consumer?->containerized === true => $this->host($database->server_id),
            default => '127.0.0.1',
        };
        $port = (string) $database->databaseServer->port;

        $variables = [
            'DB_CONNECTION' => $engine->driver(),
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => $database->name,
        ];

        $user = $this->user($database);

        if ($user !== null) {
            $variables['DB_USERNAME'] = $user->username;
            $variables['DB_PASSWORD'] = $user->password;
        }

        $credentials = $user !== null ? rawurlencode($user->username).':'.rawurlencode($user->password).'@' : '';
        $variables['DATABASE_URL'] = $this->scheme($engine).'://'.$credentials.$this->urlHost($host).':'.$port.'/'.rawurlencode($database->name);

        return $variables;
    }

    public function unreachable(string $databaseId, DatabaseConsumer $consumer): ?string
    {
        $engine = Database::query()->with('databaseServer')->find($databaseId)?->databaseServer;

        if ($engine !== null && $engine->engine->isKeyValue()) {
            return $this->keyValueUnreachable($engine, $consumer, $databaseId);
        }

        if ($engine === null || $engine->dedicated) {
            return null;
        }

        $local = "the database runs on {$engine->server_name}, which accepts connections from that server only (move it to a dedicated database server to reach it from elsewhere)";

        $elsewhere = array_values(array_diff($consumer->serverIds, [$engine->server_id]));

        if ($elsewhere !== []) {
            $names = array_map(fn (string $id) => $this->servers->find($id)?->name ?? $id, $elsewhere);

            return "{$consumer->name} runs on ".implode(', ', $names).", but {$local}.";
        }

        if ($consumer->containerized && ! $engine->container_access) {
            return "{$consumer->name} runs in a container, but containers on {$engine->server_name} can't reach its databases yet: update the server's agent (container access needs agent 0.4.5 or newer).";
        }

        return null;
    }

    /**
     * Redis / Valkey: REDIS_URL redis://default:<password>@<host>:<port> plus Laravel's REDIS_* keys. The host depends
     * on the consumer ({@see keyValueHost()}); an unreachable one keeps 127.0.0.1 ({@see unreachable()} says why).
     *
     * @return array<string, string>
     */
    private function keyValue(Database $database, ?DatabaseConsumer $consumer): array
    {
        $host = $consumer !== null ? ($this->keyValueNetwork->hostFor($database, $consumer)['host'] ?? '127.0.0.1') : '127.0.0.1';
        $port = (string) ($database->port ?? $database->databaseServer->port);
        $password = ApplyKeyValueInstance::userOf($database)?->password;

        $variables = [
            'REDIS_CLIENT' => 'phpredis',
            'REDIS_HOST' => $host,
            'REDIS_PORT' => $port,
        ];

        if ($password !== null) {
            $variables['REDIS_PASSWORD'] = $password;
        }

        $variables['REDIS_URL'] = 'redis://'.($password !== null ? 'default:'.rawurlencode($password).'@' : '').$this->urlHost($host).':'.$port;

        return $variables;
    }

    private function keyValueUnreachable(DatabaseServer $engine, DatabaseConsumer $consumer, string $databaseId): ?string
    {
        $database = Database::query()->with('databaseServer')->find($databaseId);

        return $database !== null ? $this->keyValueNetwork->hostFor($database, $consumer)['reason'] : null;
    }

    /** Most private address first: WireGuard mesh → provider private IP → public IP. */
    private function host(string $serverId): string
    {
        if ($address = $this->network->addressOf($serverId)) {
            return $address;
        }

        $server = $this->servers->find($serverId);

        return $server?->privateIpv4 ?? $server?->ipv4 ?? $server?->ipv6 ?? '127.0.0.1';
    }

    /**
     * The oldest user granted access to the database (all-privileges grants first).
     */
    private function user(Database $database): ?DatabaseUser
    {
        $users = DatabaseUser::query()
            ->where('database_server_id', $database->database_server_id)
            ->whereIn('status', [ResourceStatus::Active, ResourceStatus::Pending])
            ->whereHas('grants', fn ($q) => $q->where('database_id', $database->id))
            ->with(['grants' => fn ($q) => $q->where('database_id', $database->id)])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $users->first(fn (DatabaseUser $user) => in_array('ALL PRIVILEGES', (array) $user->grants->first()?->privileges, true))
            ?? $users->first();
    }

    private function scheme(Engine $engine): string
    {
        return $engine === Engine::PostgreSql ? 'postgresql' : 'mysql';
    }

    private function urlHost(string $host): string
    {
        return str_contains($host, ':') ? "[{$host}]" : $host;
    }
}
