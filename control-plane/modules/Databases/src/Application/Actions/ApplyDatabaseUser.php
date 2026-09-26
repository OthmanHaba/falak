<?php

namespace Kiln\Databases\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Databases\Application\AgentCommands;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Databases\Infrastructure\CommandPayloads;

/**
 * Converges a user (password, host, grants on active databases) with db.user.apply. Every apply gets a
 * new revision so its idempotency key identifies exactly one desired state. The user row is locked
 * while the revision is taken and dispatched, so concurrent applies (e.g. two databases activating
 * on two workers) never share a key or overwrite each other's command id.
 */
final class ApplyDatabaseUser
{
    public function __construct(private readonly AgentCommands $commands) {}

    /**
     * @param  bool  $background  record "agent not connected" on the user instead of throwing
     */
    public function __invoke(DatabaseUser $user, bool $background = false): void
    {
        DB::transaction(function () use ($user, $background) {
            $locked = DatabaseUser::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->setRawAttributes($locked->getAttributes(), true);
            $user->unsetRelation('grants');
            $this->apply($user, $background);
        });
    }

    private function apply(DatabaseUser $user, bool $background): void
    {
        $server = $user->databaseServer;
        $revision = $user->revision + 1;
        $payload = CommandPayloads::userPresent($server, $user);
        $key = "db.user.apply:{$user->id}:{$revision}";
        $timeout = (int) config('databases.timeouts.ddl', 300);

        $handle = $background
            ? $this->commands->tryDispatch($user->server_id, 'db.user.apply', $payload, $timeout, $key)
            : $this->commands->dispatch($user->server_id, 'db.user.apply', $payload, $timeout, $key, 'username');

        $user->forceFill($handle
            ? ['revision' => $revision, 'command_id' => $handle->id, 'status' => ResourceStatus::Pending, 'status_message' => null]
            : ['status' => ResourceStatus::Failed, 'status_message' => AgentCommands::NOT_CONNECTED],
        )->save();
    }
}
