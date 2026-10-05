<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\Identifiers;
use Falak\Databases\Application\Passwords;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateDatabaseUser
{
    public function __construct(
        private readonly SyncGrants $grants,
        private readonly ApplyDatabaseUser $apply,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{username: string, password?: ?string, host?: ?string, site_id?: ?string, grants?: list<array{database_id: string, privileges?: list<string>}>}  $data
     */
    public function __invoke(DatabaseServer $server, array $data, ?string $actorId = null): DatabaseUser
    {
        if ($server->engine->isKeyValue()) {
            throw ValidationException::withMessages(['username' => "{$server->engine->label()} instances have a single user (default); rotate its password instead."]);
        }

        $username = $data['username'];
        Identifiers::assertValid($server->engine, $username, 'username', 'username');

        if ($server->users()->where('username', $username)->exists()) {
            throw ValidationException::withMessages(['username' => "A user named \"{$username}\" already exists on {$server->server_name}."]);
        }

        $user = DB::transaction(function () use ($server, $data, $username, $actorId) {
            $user = $server->users()->create([
                'organization_id' => $server->organization_id,
                'server_id' => $server->server_id,
                'username' => $username,
                'password' => ($data['password'] ?? null) ?: Passwords::generate(),
                'host' => $server->engine->isMysqlFamily() ? (($data['host'] ?? null) ?: '%') : '%',
                'site_id' => $data['site_id'] ?? null,
                'status' => ResourceStatus::Pending,
                'created_by' => $actorId,
            ]);

            ($this->grants)($user, $data['grants'] ?? []);

            ($this->apply)($user);

            return $user;
        });

        $this->audit->record('databases.user_created', 'database_user', $user->id, [
            'username' => $username,
            'server_id' => $server->server_id,
            'databases' => $user->grants()->with('database')->get()->map(fn ($g) => $g->database?->name)->filter()->values()->all(),
        ], $server->organization_id);

        return $user;
    }
}
