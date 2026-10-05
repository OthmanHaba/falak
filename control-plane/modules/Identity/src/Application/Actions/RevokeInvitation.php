<?php

namespace Falak\Identity\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Domain\Models\Invitation;

final class RevokeInvitation
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Invitation $invitation): void
    {
        $invitation->delete();

        $this->audit->record('member.invitation_revoked', 'invitation', $invitation->id, ['email' => $invitation->email], $invitation->organization_id);
    }
}
