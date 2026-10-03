<?php

namespace Kiln\Databases\Application\KeyValue;

use Illuminate\Validation\ValidationException;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Identity\Contracts\AuditLog;

/**
 * Changes an instance's memory limit, eviction policy or persistence and re-applies it (the agent restarts the
 * instance; with rdb or aof its data is saved on the way down and loaded back).
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

        $database->forceFill(['settings' => $after])->save();
        ($this->apply)($database);

        $this->audit->record('databases.instance_settings_updated', 'database', $database->id, ['before' => $before, 'after' => $after], $database->organization_id);

        return $database;
    }
}
