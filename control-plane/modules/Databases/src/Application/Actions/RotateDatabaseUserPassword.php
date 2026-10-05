<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\Passwords;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RotateDatabaseUserPassword
{
    public function __construct(
        private readonly ApplyDatabaseUser $apply,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(DatabaseUser $user, #[\SensitiveParameter] ?string $password = null): void
    {
        // Redis / Valkey passwords go into the instance's config and REDIS_URL unquoted (db.redis.apply schema).
        if ($password !== null && $password !== '' && $user->databaseServer->engine->isKeyValue() && preg_match('/^[A-Za-z0-9._~-]{12,128}$/', $password) !== 1) {
            throw ValidationException::withMessages(['password' => 'Use 12–128 letters, digits, dots, dashes, underscores or tildes.']);
        }

        DB::transaction(function () use ($user, $password) {
            $user->forceFill(['password' => $password ?: Passwords::generate()])->save();
            ($this->apply)($user);
        });

        $this->audit->record('databases.user_password_rotated', 'database_user', $user->id, ['username' => $user->username, 'generated' => $password === null || $password === ''], $user->organization_id);
    }
}
