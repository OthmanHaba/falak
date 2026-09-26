<?php

namespace Kiln\Databases\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Databases\Application\Passwords;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Identity\Contracts\AuditLog;

final class RotateDatabaseUserPassword
{
    public function __construct(
        private readonly ApplyDatabaseUser $apply,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(DatabaseUser $user, #[\SensitiveParameter] ?string $password = null): void
    {
        DB::transaction(function () use ($user, $password) {
            $user->forceFill(['password' => $password ?: Passwords::generate()])->save();
            ($this->apply)($user);
        });

        $this->audit->record('databases.user_password_rotated', 'database_user', $user->id, ['username' => $user->username, 'generated' => $password === null || $password === ''], $user->organization_id);
    }
}
