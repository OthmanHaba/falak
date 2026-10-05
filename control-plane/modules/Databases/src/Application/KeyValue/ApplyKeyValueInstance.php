<?php

namespace Falak\Databases\Application\KeyValue;

use Illuminate\Support\Facades\DB;
use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Infrastructure\CommandPayloads;

/**
 * Converges a Redis / Valkey instance with db.redis.apply (configuration, password, unit). Every apply takes a new
 * revision of the instance's `default` user, so its idempotency key names exactly one desired state; the command id
 * is recorded on the instance and on the user (a password rotation settles the user, a creation the instance). The
 * network part (bind, containers) is recorded as `network.wanted` so a change of who uses the instance re-applies it
 * only when it changes what it listens on (ConvergeKeyValueNetwork).
 */
final class ApplyKeyValueInstance
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly KeyValueNetwork $network,
    ) {}

    /**
     * @param  bool  $background  record "agent not connected" instead of throwing
     */
    public function __invoke(Database $database, bool $background = false): void
    {
        DB::transaction(function () use ($database, $background) {
            $user = self::userOf($database, lock: true);
            $server = $database->databaseServer;
            $revision = ($user?->revision ?? 0) + 1;
            $desired = $this->network->desired($database);
            $wanted = ['bind' => $desired['bind'], 'containers' => $desired['containers']];
            $payload = CommandPayloads::redisApply($server, $database, (string) $user?->password, $wanted);
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
            $database->forceFill(['command_id' => $handle->id, 'status_message' => null, 'network' => [...(array) $database->network, 'wanted' => $wanted]])->save();
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
