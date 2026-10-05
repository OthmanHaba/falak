<?php

namespace Falak\Identity\Application;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Falak\Identity\Application\Actions\AcceptInvitation;
use Falak\Identity\Domain\Models\Invitation;
use Falak\Identity\Domain\Models\User;

/**
 * Who may sign up (config identity.registration, FALAK_REGISTRATION): open, invite or closed. A panel without any
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
     * Run $signUp (check who may sign up, then create the account) while no other sign-up runs: otherwise two guests
     * could both pass the first-account exception of an empty panel before either account exists.
     *
     * @template T
     *
     * @param  Closure(): T  $signUp
     * @return T
     */
    public function exclusively(Closure $signUp): mixed
    {
        try {
            return Cache::lock('identity:registration', 30)->block((int) config('identity.registration_lock_wait', 10), $signUp);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['email' => 'Another sign-up is in progress. Try again in a moment.']);
        }
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
