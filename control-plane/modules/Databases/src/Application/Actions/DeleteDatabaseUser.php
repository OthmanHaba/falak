<?php

namespace Kiln\Databases\Application\Actions;

use Kiln\Databases\Application\AgentCommands;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Databases\Infrastructure\CommandPayloads;
use Kiln\Identity\Contracts\AuditLog;

/**
 * Drops the account (db.user.apply state=absent); the row goes once the agent confirms.
 */
final class DeleteDatabaseUser
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(DatabaseUser $user): void
    {
        $revision = $user->revision + 1;

        $handle = $this->commands->dispatch(
            $user->server_id,
            'db.user.apply',
            CommandPayloads::userAbsent($user->databaseServer, $user),
            (int) config('databases.timeouts.ddl', 300),
            "db.user.absent:{$user->id}:{$user->host}:{$revision}",
            'user',
        );

        $user->forceFill(['status' => ResourceStatus::Deleting, 'command_id' => $handle->id, 'revision' => $revision, 'status_message' => null])->save();

        $this->audit->record('databases.user_delete_requested', 'database_user', $user->id, ['username' => $user->username, 'server_id' => $user->server_id], $user->organization_id);
    }
}
