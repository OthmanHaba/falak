<?php

namespace Kiln\Identity\Application;

use Kiln\Identity\Application\Actions\AcceptInvitation;
use Kiln\Identity\Domain\Models\Invitation;
use Kiln\Identity\Domain\Models\User;

/**
 * Who may sign up (config identity.registration, KILN_REGISTRATION): open, invite or closed. A panel without any
 * user is always open so the first administrator can register.
 */
final class Registration
{
    public const OPEN = 'open';

    public const INVITE = 'invite';

    public const CLOSED = 'closed';

    public function mode(): string
    {
        $mode = strtolower(trim((string) config('identity.registration', self::OPEN)));
        $mode = in_array($mode, [self::OPEN, self::INVITE, self::CLOSED], true) ? $mode : self::CLOSED;

        if ($mode !== self::OPEN && ! User::query()->exists()) {
            return self::OPEN;
        }

        return $mode;
    }

    /**
     * Invite mode needs proof of the invitation: its token (from the invitation link), pending, sent to this address.
     */
    public function allows(string $email, ?string $invitationToken): bool
    {
        return match ($this->mode()) {
            self::OPEN => true,
            self::INVITE => $this->invitationFor($invitationToken, $email) !== null,
            default => false,
        };
    }

    /** The pending invitation behind a token, when it was sent to $email (any address when null). */
    public function invitationFor(?string $token, ?string $email = null): ?Invitation
    {
        if ($token === null || $token === '') {
            return null;
        }

        $invitation = AcceptInvitation::findPending($token);

        if ($invitation === null || ($email !== null && mb_strtolower($invitation->email) !== mb_strtolower($email))) {
            return null;
        }

        return $invitation;
    }

    /** The token of an invitation link the guest was sent to the login page from (`url.intended`). */
    public static function tokenFromUrl(?string $url): ?string
    {
        $path = $url !== null ? (string) parse_url($url, PHP_URL_PATH) : '';

        return preg_match('#^/invitations/([^/]+)$#', $path, $m) === 1 ? rawurldecode($m[1]) : null;
    }
}
