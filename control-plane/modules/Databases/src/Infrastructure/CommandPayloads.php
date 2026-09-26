<?php

namespace Kiln\Databases\Infrastructure;

use Kiln\Databases\Domain\Enums\Compression;
use Kiln\Databases\Domain\Enums\Engine;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Databases\Domain\Models\Grant;

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

        return $payload;
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
    public static function restore(Engine $engine, string $database, Compression $compression, string $downloadUrl, ?string $sha256): array
    {
        return array_filter([
            'engine' => $engine->protocol(),
            'database' => $database,
            'compression' => $compression->value,
            'source' => ['kind' => 'url', 'url' => $downloadUrl],
            'sha256' => $sha256,
        ], fn ($value) => $value !== null);
    }
}
