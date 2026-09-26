<?php

namespace Kiln\Identity\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Domain\Models\User;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;

final class ConfirmTwoFactor
{
    public function __construct(
        private readonly ConfirmTwoFactorAuthentication $confirm,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(User $user, string $code): void
    {
        ($this->confirm)($user, $code);

        $this->audit->recordPersonal('two_factor.enabled', $user->id);
    }
}
