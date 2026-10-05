<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Identity\Contracts\AuditLog;

/**
 * Returns the decrypted password (the caller has checked databases.credentials.reveal). Always audited.
 */
final class RevealDatabaseUserPassword
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(DatabaseUser $user): string
    {
        $this->audit->record('databases.user_password_revealed', 'database_user', $user->id, ['username' => $user->username, 'server_id' => $user->server_id], $user->organization_id);

        return $user->password;
    }
}
