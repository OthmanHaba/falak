<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\Passwords;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A new password for a SQL user (db.user.apply); a Redis / Valkey `default` user's password is the instance's
 * (db.instance.password, {@see InstanceLifecycle::rotatePassword()}).
 */
final class RotateDatabaseUserPassword
{
    public function __construct(
        private readonly ApplyDatabaseUser $apply,
        private readonly InstanceLifecycle $lifecycle,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(DatabaseUser $user, #[\SensitiveParameter] ?string $password = null): void
    {
        if ($user->instance->engine->isKeyValue()) {
            $this->lifecycle->rotatePassword($user->instance, $password);

            return;
        }

        if ($password !== null && $password !== '' && preg_match('/^[\x21-\x7e]{12,128}$/', $password) !== 1) {
            throw ValidationException::withMessages(['password' => 'Use 12–128 printable characters without spaces.']);
        }

        DB::transaction(function () use ($user, $password) {
            $user->forceFill(['password' => $password ?: Passwords::generate()])->save();
            ($this->apply)($user);
        });

        $this->audit->record('databases.user_password_rotated', 'database_user', $user->id, ['username' => $user->username, 'generated' => $password === null || $password === ''], $user->organization_id);
    }
}
