<?php

namespace Falak\Identity\Application\Actions;

use Falak\Identity\Application\Notifications\OrganizationInvitation;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\Invitation;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InviteMember
{
    public const TTL_DAYS = 7;

    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Organization $organization, User $inviter, string $email, Role $role): Invitation
    {
        $email = mb_strtolower(trim($email));

        if (! in_array($role, Role::assignable(), true)) {
            throw ValidationException::withMessages(['role' => 'That role cannot be granted by invitation.']);
        }

        if ($organization->members()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'That user is already a member of this organization.']);
        }

        // Re-inviting replaces any outstanding invitation for the same address.
        $organization->invitations()->where('email', $email)->whereNull('accepted_at')->delete();

        $token = Str::random(48);

        $invitation = $organization->invitations()->create([
            'email' => $email,
            'role' => $role,
            'token_hash' => Invitation::hashToken($token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        Notification::route('mail', $email)->notify(new OrganizationInvitation(
            organizationName: $organization->name,
            inviterName: $inviter->name,
            role: $role,
            url: route('invitations.show', $token),
        ));

        $this->audit->record('member.invited', 'invitation', $invitation->id, ['email' => $email, 'role' => $role->value], $organization->id);

        return $invitation;
    }
}
