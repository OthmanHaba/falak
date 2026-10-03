<?php

namespace Kiln\Databases\Application\KeyValue;

use Illuminate\Support\Facades\DB;
use Kiln\Databases\Application\AgentCommands;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Databases\Infrastructure\CommandPayloads;

/**
 * Converges a Redis / Valkey instance with db.redis.apply (configuration, password, unit). Every apply takes a new
 * revision of the instance's `default` user, so its idempotency key names exactly one desired state; the command id
 * is recorded on the instance and on the user (a password rotation settles the user, a creation the instance).
 */
final class ApplyKeyValueInstance
{
    public function __construct(private readonly AgentCommands $commands) {}

    /**
     * @param  bool  $background  record "agent not connected" instead of throwing
     */
    public function __invoke(Database $database, bool $background = false): void
    {
        DB::transaction(function () use ($database, $background) {
            $user = self::userOf($database, lock: true);
            $server = $database->databaseServer;
            $revision = ($user?->revision ?? 0) + 1;
            $payload = CommandPayloads::redisApply($server, $database, (string) $user?->password);
            $key = "db.redis.apply:{$database->id}:{$revision}";
            $timeout = (int) config('databases.timeouts.redis_apply', 3600);

            $handle = $background
                ? $this->commands->tryDispatch($database->server_id, 'db.redis.apply', $payload, $timeout, $key)
                : $this->commands->dispatch($database->server_id, 'db.redis.apply', $payload, $timeout, $key, 'name');

            if ($handle === null) {
                $user?->forceFill(['status' => ResourceStatus::Failed, 'status_message' => AgentCommands::NOT_CONNECTED])->save();
                $database->forceFill(['status_message' => AgentCommands::NOT_CONNECTED])->save();

                return;
            }

            $user?->forceFill(['revision' => $revision, 'command_id' => $handle->id, 'status' => ResourceStatus::Pending, 'status_message' => null])->save();
            $database->forceFill(['command_id' => $handle->id, 'status_message' => null])->save();
        });
    }

    /** The instance's `default` user (the requirepass password): the user granted on it. */
    public static function userOf(Database $database, bool $lock = false): ?DatabaseUser
    {
        return DatabaseUser::query()
            ->where('database_server_id', $database->database_server_id)
            ->whereHas('grants', fn ($q) => $q->where('database_id', $database->id))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->orderBy('created_at')
            ->first();
    }
}
