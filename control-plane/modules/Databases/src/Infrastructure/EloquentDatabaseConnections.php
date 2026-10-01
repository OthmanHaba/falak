<?php

namespace Kiln\Databases\Infrastructure;

use Kiln\Databases\Contracts\DatabaseConnections;
use Kiln\Databases\Domain\Enums\Engine;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Network\Contracts\PrivateNetwork;
use Kiln\Servers\Contracts\ServerDirectory;

final class EloquentDatabaseConnections implements DatabaseConnections
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly PrivateNetwork $network,
    ) {}

    public function variables(string $databaseId): array
    {
        $database = Database::query()->with('databaseServer')->find($databaseId);

        if ($database === null) {
            return [];
        }

        $engine = $database->databaseServer->engine;
        // Engines on app/worker servers listen on localhost only (see CommandPayloads::remote): sites on that
        // server reach them on 127.0.0.1. Only dedicated database servers listen on the network.
        $host = $database->databaseServer->dedicated ? $this->host($database->server_id) : '127.0.0.1';
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
