<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Infrastructure\CommandPayloads;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Validation\ValidationException;

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
        if ($user->instance->engine->isKeyValue()) {
            throw ValidationException::withMessages(['user' => 'The default user of an instance goes with the instance: delete the instance instead.']);
        }

        $revision = $user->revision + 1;

        $handle = $this->commands->dispatch(
            $user->server_id,
            'db.user.apply',
            CommandPayloads::userAbsent($user->instance, $user),
            (int) config('databases.timeouts.ddl', 300),
            "db.user.absent:{$user->id}:{$user->host}:{$revision}",
            'user',
        );

        $user->forceFill(['status' => ResourceStatus::Deleting, 'command_id' => $handle->id, 'revision' => $revision, 'status_message' => null])->save();

        $this->audit->record('databases.user_delete_requested', 'database_user', $user->id, ['username' => $user->username, 'server_id' => $user->server_id], $user->organization_id);
    }
}
