<?php

namespace Kiln\Databases\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Databases\Application\AgentCommands;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Databases\Infrastructure\CommandPayloads;
use Kiln\Identity\Contracts\AuditLog;

/**
 * Changes a user's grants and/or MySQL host and re-applies the full user state.
 */
final class UpdateDatabaseUser
{
    public function __construct(
        private readonly SyncGrants $grants,
        private readonly ApplyDatabaseUser $apply,
        private readonly AgentCommands $commands,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{host?: ?string, grants: list<array{database_id: string, privileges?: list<string>}>}  $data
     */
    public function __invoke(DatabaseUser $user, array $data): void
    {
        $server = $user->databaseServer;
        $newHost = $server->engine->isMysqlFamily() && ! empty($data['host']) ? $data['host'] : $user->host;

        DB::transaction(function () use ($user, $data, $server, $newHost) {
            // MySQL accounts are user@host: a host change drops the old account first.
            if ($newHost !== $user->host) {
                $this->commands->dispatch(
                    $user->server_id,
                    'db.user.apply',
                    CommandPayloads::userAbsent($server, $user),
                    (int) config('databases.timeouts.ddl', 300),
                    "db.user.absent:{$user->id}:{$user->host}:".($user->revision + 1),
                    'host',
                );
                $user->forceFill(['host' => $newHost])->save();
            }

            ($this->grants)($user, $data['grants']);
            ($this->apply)($user);
        });

        $this->audit->record('databases.user_updated', 'database_user', $user->id, [
            'username' => $user->username,
            'host' => $user->host,
            'databases' => $user->grants()->with('database')->get()->map(fn ($g) => $g->database?->name)->filter()->values()->all(),
        ], $user->organization_id);
    }
}
