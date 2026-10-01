<?php

namespace Kiln\Identity\Application;

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

    public function allows(string $email): bool
    {
        return match ($this->mode()) {
            self::OPEN => true,
            self::INVITE => Invitation::query()->pending()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists(),
            default => false,
        };
    }
}
