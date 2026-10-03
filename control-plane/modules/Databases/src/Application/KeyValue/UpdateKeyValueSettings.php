<?php

namespace Kiln\Databases\Application\KeyValue;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Identity\Contracts\AuditLog;

/**
 * Changes an instance's memory limit, eviction policy or persistence and re-applies it. The agent changes them on the
 * running instance (no restart); switching persistence keeps the data, except that with `none` nothing is written
 * and the next restart starts empty.
 */
final class UpdateKeyValueSettings
{
    public function __construct(
        private readonly KeyValueSettings $settings,
        private readonly ApplyKeyValueInstance $apply,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{maxmemory_mb?: ?int, eviction?: ?string, persistence?: ?string}  $input
     */
    public function __invoke(Database $database, array $input): Database
    {
        if (! $database->databaseServer->engine->isKeyValue()) {
            throw ValidationException::withMessages(['database' => 'Only Redis and Valkey instances have these settings.']);
        }

        if ($database->status === ResourceStatus::Deleting) {
            throw ValidationException::withMessages(['database' => 'The instance is being deleted.']);
        }

        $before = KeyValueSettings::of($database);
        $after = $this->settings->resolve($database->server_id, $input, $before);

        if ($after === $before) {
            return $database;
        }

        // Saved only together with the dispatch: when the agent can't be reached, nothing changes and a retry applies.
        DB::transaction(function () use ($database, $after) {
            // The `default` user's row first, then the instance's: the order a password rotation locks them in
            // (ApplyDatabaseUser → ApplyKeyValueInstance), so a save next to a rotation waits instead of deadlocking.
            ApplyKeyValueInstance::userOf($database, lock: true);
            $database->forceFill(['settings' => [...(array) $database->settings, ...$after]])->save();
            ($this->apply)($database);
        });

        $this->audit->record('databases.instance_settings_updated', 'database', $database->id, ['before' => $before, 'after' => $after], $database->organization_id);

        return $database;
    }
}
