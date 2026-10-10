<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Fleet\Contracts\Data\CommandHandle;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Validation\ValidationException;

/**
 * Run a user's script against one SQL database, inside its container (system.exec → `docker exec`), as the oldest user
 * granted access to it: e.g. a preview's sanitize script after a restore. `sql` feeds the script to psql / mysql
 * (stops at the first error, Postgres in one transaction); `command` runs it with `sh` in the container, with the
 * connection in DB_* (and PG* / MYSQL_PWD) variables. Any failure fails the command.
 *
 * The password travels in the payload's env, masked in the output and forgotten once the command settled.
 */
final class RunDatabaseScript
{
    public const KEY_PREFIX = 'databases.script:';

    /** Payload secrets dropped once the command settled. */
    public const SECRET_PATHS = ['env.DB_PASSWORD'];

    public const MAX_SCRIPT = 65536;

    public function __construct(
        private readonly AgentCommands $commands,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  'sql'|'command'  $kind
     *
     * @throws ValidationException
     */
    public function __invoke(Database $database, string $kind, string $script, string $key, int $timeout = 900): CommandHandle
    {
        $database->loadMissing('instance');
        $instance = $database->instance;
        $script = str_replace("\r\n", "\n", $script);

        if ($instance->engine->isKeyValue()) {
            throw ValidationException::withMessages(['script' => "{$instance->engine->label()} keyspaces have no SQL to run a script against."]);
        }

        if (! in_array($kind, ['sql', 'command'], true) || trim($script) === '' || strlen($script) > self::MAX_SCRIPT) {
            throw ValidationException::withMessages(['script' => 'Give a SQL or command script of at most 64 KB.']);
        }

        if ($database->status !== ResourceStatus::Active || ! $instance->isRunning()) {
            throw ValidationException::withMessages(['database' => "{$database->name} is not running."]);
        }

        $user = DatabaseUser::query()
            ->where('database_instance_id', $database->database_instance_id)
            ->where('status', ResourceStatus::Active)
            ->whereHas('grants', fn ($q) => $q->where('database_id', $database->id))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($user === null) {
            throw ValidationException::withMessages(['database' => "{$database->name} has no user to run the script as."]);
        }

        $postgres = ! $instance->engine->isMysqlFamily();
        $env = [
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => (string) $instance->engine->defaultPort(),
            'DB_DATABASE' => $database->name,
            'DB_USERNAME' => $user->username,
            'DB_PASSWORD' => (string) $user->password,
        ];
        $container = 'falak-db-'.$instance->id;
        // docker exec -e NAME copies the exported value of NAME into the container: no value is on a command line.
        $export = $postgres
            ? 'export PGHOST="$DB_HOST" PGPORT="$DB_PORT" PGUSER="$DB_USERNAME" PGPASSWORD="$DB_PASSWORD" PGDATABASE="$DB_DATABASE"'
            : 'export MYSQL_PWD="$DB_PASSWORD"';
        $connection = '-e DB_HOST -e DB_PORT -e DB_DATABASE -e DB_USERNAME -e DB_PASSWORD '.($postgres ? '-e PGHOST -e PGPORT -e PGUSER -e PGPASSWORD -e PGDATABASE' : '-e MYSQL_PWD');

        $run = match (true) {
            $kind === 'command' => "exec docker exec -i {$connection} {$container} sh -s",
            $postgres => "exec docker exec -i {$connection} {$container} psql -X -q -v ON_ERROR_STOP=1 --single-transaction -f -",
            default => "exec docker exec -i {$connection} {$container} sh -c 'exec \"\$(command -v mariadb || command -v mysql)\" -h\"\$DB_HOST\" -P\"\$DB_PORT\" -u\"\$DB_USERNAME\" \"\$DB_DATABASE\"'",
        };

        $handle = $this->commands->dispatch($database->server_id, 'system.exec', [
            'script' => "set -euo pipefail\n{$export}\n{$run}\n",
            'user' => 'root',
            'env' => $env,
            'stdin' => $script,
            'mask' => ['DB_PASSWORD'],
        ], $timeout, self::KEY_PREFIX.$key, 'database');

        $this->audit->record('databases.script_run', 'database', $database->id, ['kind' => $kind, 'bytes' => strlen($script), 'command_id' => $handle->id], $database->organization_id);

        return $handle;
    }
}
