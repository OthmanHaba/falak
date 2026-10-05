<?php

namespace Falak\Identity\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Domain\Models\User;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/**
 * Starts 2FA enrollment: generates a secret + recovery codes. Takes effect after {@see ConfirmTwoFactor}.
 */
final class EnableTwoFactor
{
    public function __construct(
        private readonly EnableTwoFactorAuthentication $enable,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(User $user): void
    {
        ($this->enable)($user, force: $user->two_factor_confirmed_at === null);

        $this->audit->recordPersonal('two_factor.enrollment_started', $user->id);
    }
}
