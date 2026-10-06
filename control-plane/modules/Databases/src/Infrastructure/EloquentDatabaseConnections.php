<?php

namespace Falak\Databases\Infrastructure;

use Falak\Databases\Application\KeyValue\ApplyKeyValueInstance;
use Falak\Databases\Application\KeyValue\KeyValueNetwork;
use Falak\Databases\Contracts\Data\DatabaseConsumer;
use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Servers\Contracts\ServerDirectory;

final class EloquentDatabaseConnections implements DatabaseConnections
{
    public function __construct(
        private readonly ServerDirectory $servers,
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

        // Unresolved (unreachable() says why): 127.0.0.1, never another address, like Redis / Valkey.
        $host = $this->sqlHost($database, $consumer)['host'] ?? '127.0.0.1';
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
        $database = Database::query()->with('databaseServer')->find($databaseId);

        if ($database === null) {
            return null;
        }

        return $database->databaseServer->engine->isKeyValue()
            ? $this->keyValueNetwork->hostFor($database, $consumer)['reason']
            : $this->sqlHost($database, $consumer)['reason'];
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

    /**
     * Where the consumer reaches a PostgreSQL / MySQL / MariaDB database, by the rules of Redis / Valkey instances
     * ({@see KeyValueNetwork::hostFor()}), never a public address:
     *
     * - native on the engine's server (or no consumer): 127.0.0.1;
     * - containers on the engine's server: the Docker bridge address (docker0), once the engine accepts containers
     *   (container access on an app/worker server; a dedicated database server listens on the network anyway);
     * - other servers: only for a dedicated database server (engines on app/worker servers stay local), its address on
     *   a private network all of the consumer's servers share with it — a Falak private network (WireGuard) first,
     *   else the provider private network where membership is known (databases.key_value.provider_private_networks).
     *
     * Anything else stays unresolved, with the reason.
     *
     * @return array{host: ?string, reason: ?string}
     */
    private function sqlHost(Database $database, ?DatabaseConsumer $consumer): array
    {
        $engine = $database->databaseServer;

        if ($consumer === null) {
            return ['host' => '127.0.0.1', 'reason' => null];
        }

        $elsewhere = array_values(array_diff(array_map('strtolower', $consumer->serverIds), [$database->server_id]));

        if ($elsewhere === [] && ! $consumer->containerized) {
            return ['host' => '127.0.0.1', 'reason' => null];
        }

        if (! $engine->dedicated && $elsewhere !== []) {
            return ['host' => null, 'reason' => "{$consumer->name} runs on {$this->names($elsewhere)}, but the database runs on {$engine->server_name}, which accepts connections from that server only (move it to a dedicated database server to reach it from elsewhere)."];
        }

        if ($elsewhere === []) {
            if (! $engine->dedicated && ! $engine->container_access) {
                return ['host' => null, 'reason' => "{$consumer->name} runs in a container, but containers on {$engine->server_name} can't reach its databases yet: update the server's agent (container access needs agent 0.4.5 or newer)."];
            }

            if (KeyValueNetwork::containerRanges() === []) {
                return ['host' => null, 'reason' => "{$consumer->name} runs in a container, but container access to databases is turned off (FALAK_DOCKER_NETWORKS)."];
            }

            return ['host' => $this->bridgeHost($database->server_id), 'reason' => null];
        }

        $reach = $this->keyValueNetwork->reach($database, $consumer->serverIds);

        if ($reach['host'] === null) {
            $missing = $reach['missing'] !== [] ? $reach['missing'] : $elsewhere;

            return ['host' => null, 'reason' => "{$consumer->name} runs on {$this->names($missing)}, which shares no private network with {$engine->server_name}, and database references never point at a public address. Add both servers to a private network (Network → Private networks)".($reach['missing'] === [] ? ' — one network that all of the site\'s servers share' : '').'.'];
        }

        return ['host' => $reach['host'], 'reason' => null];
    }

    /**
     * The Docker default bridge's address on the server: what its agent reported for docker0 (a Redis / Valkey
     * instance there listens on it), else databases.docker_bridge_host (Docker's default 172.17.0.1). Every bridge
     * network's containers reach it through their own gateway; the firewall lets the Docker ranges in on the bridges.
     */
    private function bridgeHost(string $serverId): string
    {
        $reported = Database::query()->where('server_id', $serverId)->whereNotNull('network')->orderBy('created_at')->get(['id', 'network'])
            ->map(fn (Database $instance) => ((array) $instance->network)['container_host'] ?? null)
            ->first(fn ($host) => is_string($host) && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);

        return $reported ?? (string) config('databases.docker_bridge_host', '172.17.0.1');
    }

    /** @param  list<string>  $serverIds */
    private function names(array $serverIds): string
    {
        return implode(', ', array_map(fn (string $id) => $this->servers->find($id)?->name ?? $id, $serverIds));
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
