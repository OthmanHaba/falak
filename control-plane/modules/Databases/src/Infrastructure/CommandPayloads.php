<?php

namespace Falak\Databases\Infrastructure;

use Falak\Databases\Domain\Enums\Compression;
use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Grant;

/**
 * Builds db.* agent command payloads (contracts/agent-protocol/commands/db.*.schema.json).
 */
final class CommandPayloads
{
    /**
     * db.instance.create / db.instance.update: the instance's full desired state. The password is the superuser's
     * (Redis / Valkey: `default`'s); the agent hands it to the container as a file, never in its environment.
     *
     * The published addresses are the confirmed ones unless $addresses (pending ones being applied) are given; the
     * allowed sources are what the caller last computed (firewall_sources).
     *
     * @param  ?array{certificate: string, private_key: string, ca: string}  $tls
     * @param  ?list<string>  $addresses
     * @return array<string, mixed>
     */
    public static function instance(DatabaseInstance $instance, ?array $tls = null, ?array $addresses = null): array
    {
        $settings = (array) ($instance->settings ?? []);

        if ($instance->require_tls || $instance->public_access) {
            $settings['require_tls'] = true;
        }

        $publish = array_filter([
            'addresses' => array_values($addresses ?? (array) ($instance->published_addresses ?? [])) ?: null,
            'public' => $instance->public_access ?: null,
        ], fn ($value) => $value !== null);

        if ($publish !== []) {
            $publish['allowed_sources'] = array_values((array) ($instance->firewall_sources ?? []));
        }

        return [
            'instance' => array_filter([
                'id' => $instance->id,
                'engine' => $instance->engine->protocol(),
                'version' => $instance->version,
                'image' => $instance->image,
                'digest' => $instance->image_digest,
                'volume_id' => $instance->volume_id,
                'host_port' => $instance->host_port,
                'memory_bytes' => $instance->memory_bytes,
                'cpus' => $instance->cpus,
                'settings' => $settings !== [] ? $settings : null,
                'network' => $instance->network(),
                'aliases' => [$instance->hostname],
                'publish' => $publish !== [] ? $publish : null,
                'tls' => $tls,
            ], fn ($value) => $value !== null),
            'password' => $instance->root_password,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function create(DatabaseInstance $instance, Database $database): array
    {
        return array_filter([
            'instance' => $instance->id,
            'engine' => $instance->engine->protocol(),
            'name' => $database->name,
            'charset' => $instance->engine->isMysqlFamily() ? ($database->charset ?: $instance->engine->defaultCharset()) : null,
            'collation' => $instance->engine->isMysqlFamily() ? ($database->collation ?: $instance->engine->defaultCollation()) : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function drop(DatabaseInstance $instance, Database $database): array
    {
        return ['instance' => $instance->id, 'engine' => $instance->engine->protocol(), 'name' => $database->name];
    }

    /**
     * Full desired state of a user: grants only cover databases that exist in the instance (active).
     *
     * @return array<string, mixed>
     */
    public static function userPresent(DatabaseInstance $instance, DatabaseUser $user): array
    {
        $payload = [
            'instance' => $instance->id,
            'engine' => $instance->engine->protocol(),
            'username' => $user->username,
            'password' => $user->password,
            'grants' => self::grants($user),
            'state' => 'present',
        ];

        if ($instance->engine->isMysqlFamily()) {
            $payload['host'] = $user->host;
        }

        return $payload;
    }

    /**
     * @return list<array{database: string, privileges: list<string>}>
     */
    public static function grants(DatabaseUser $user, bool $activeOnly = true): array
    {
        return $user->grants()
            ->with('database')
            ->get()
            ->filter(fn (Grant $grant) => $grant->database !== null && (! $activeOnly || $grant->database->status === ResourceStatus::Active))
            ->sortBy(fn (Grant $grant) => $grant->database->name)
            ->map(fn (Grant $grant) => ['database' => $grant->database->name, 'privileges' => array_values($grant->privileges ?: ['ALL PRIVILEGES'])])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function userAbsent(DatabaseInstance $instance, DatabaseUser $user): array
    {
        $payload = ['instance' => $instance->id, 'engine' => $instance->engine->protocol(), 'username' => $user->username, 'state' => 'absent'];

        if ($instance->engine->isMysqlFamily()) {
            $payload['host'] = $user->host;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function backup(DatabaseInstance $instance, string $database, Compression $compression, string $uploadUrl): array
    {
        return [
            'instance' => $instance->id,
            'engine' => $instance->engine->protocol(),
            'database' => $database,
            'compression' => $compression->value,
            'destination' => ['kind' => 'presigned_url', 'url' => $uploadUrl],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function restore(DatabaseInstance $instance, string $database, Compression $compression, string $downloadUrl, ?string $sha256): array
    {
        $row = $instance->databases()->where('name', $database)->first();

        return array_filter([
            'instance' => $instance->id,
            'engine' => $instance->engine->protocol(),
            'database' => $database,
            'compression' => $compression->value,
            'source' => ['kind' => 'url', 'url' => $downloadUrl],
            'sha256' => $sha256,
            'owner' => $row !== null ? self::owner($instance, $row) : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * PostgreSQL: the role that owns a database's objects after a restore or a copy (pg_restore --no-owner leaves them to
     * the superuser, and the app's migrations then fail): the oldest user with all privileges on it.
     */
    public static function owner(DatabaseInstance $instance, Database $database): ?string
    {
        if ($instance->engine !== Engine::PostgreSql) {
            return null;
        }

        return Grant::query()->with('user')->where('database_id', $database->id)->get()
            ->filter(fn (Grant $grant) => $grant->user !== null && $grant->user->status !== ResourceStatus::Deleting && in_array('ALL PRIVILEGES', (array) $grant->privileges, true))
            ->sortBy(fn (Grant $grant) => [$grant->user->created_at, $grant->user->id])
            ->first()?->user->username;
    }

    /**
     * db.instance.upgrade (major): copy every database of $source into $target (a new instance of the new major), apply
     * the users there, then move the DNS name apps use over to $target and stop $source.
     *
     * @return array<string, mixed>
     */
    public static function upgrade(DatabaseInstance $source, DatabaseInstance $target): array
    {
        $sql = ! $source->engine->isKeyValue();

        return array_filter([
            'mode' => 'major',
            'source' => ['id' => $source->id, 'engine' => $source->engine->protocol()],
            'target' => ['id' => $target->id, 'engine' => $target->engine->protocol()],
            'databases' => $sql ? $source->databases()->where('status', ResourceStatus::Active)->get()
                ->map(fn (Database $database) => array_filter([
                    'name' => $database->name,
                    'charset' => $source->engine->isMysqlFamily() ? ($database->charset ?: $source->engine->defaultCharset()) : null,
                    'collation' => $source->engine->isMysqlFamily() ? ($database->collation ?: $source->engine->defaultCollation()) : null,
                    'owner' => self::owner($source, $database),
                ], fn ($value) => $value !== null))->values()->all() : [],
            'users' => $sql ? $source->users()->where('status', '!=', ResourceStatus::Deleting)->get()
                ->map(fn (DatabaseUser $user) => array_filter([
                    'username' => $user->username,
                    'password' => $user->password,
                    'host' => $source->engine->isMysqlFamily() ? $user->host : null,
                    'grants' => self::grants($user),
                ], fn ($value) => $value !== null))->values()->all() : [],
            'network' => $source->network(),
            'alias' => $source->hostname,
        ], fn ($value) => $value !== null);
    }
}
