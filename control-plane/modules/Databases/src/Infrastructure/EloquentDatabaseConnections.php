<?php

namespace Falak\Databases\Infrastructure;

use Falak\Databases\Application\InstanceNetwork;
use Falak\Databases\Contracts\Data\DatabaseConsumer;
use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseUser;

final class EloquentDatabaseConnections implements DatabaseConnections
{
    public function __construct(private readonly InstanceNetwork $network) {}

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
        $database = Database::query()->with('instance')->find($databaseId);

        if ($database === null) {
            return [];
        }

        $instance = $database->instance;
        $engine = $instance->engine;
        $resolved = $this->network->hostFor($instance, $consumer);
        // Unresolved (unreachable() says why): 127.0.0.1 and the host port, never another address.
        $host = $resolved['host'] ?? '127.0.0.1';
        $port = (string) ($resolved['host'] !== null ? $resolved['port'] : $instance->host_port);

        if ($engine->isKeyValue()) {
            $password = $instance->root_password;

            return [
                'REDIS_CLIENT' => 'phpredis',
                'REDIS_HOST' => $host,
                'REDIS_PORT' => $port,
                'REDIS_PASSWORD' => $password,
                'REDIS_URL' => 'redis://default:'.rawurlencode($password).'@'.$this->urlHost($host).':'.$port,
            ];
        }

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
        $database = Database::query()->with('instance')->find($databaseId);

        return $database !== null ? $this->network->hostFor($database->instance, $consumer)['reason'] : null;
    }

    /**
     * The oldest user granted access to the database (all-privileges grants first).
     */
    private function user(Database $database): ?DatabaseUser
    {
        $users = DatabaseUser::query()
            ->where('database_instance_id', $database->database_instance_id)
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
