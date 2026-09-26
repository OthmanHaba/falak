<?php

namespace Kiln\Identity\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Domain\Models\User;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

final class DisableTwoFactor
{
    public function __construct(
        private readonly DisableTwoFactorAuthentication $disable,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(User $user): void
    {
        ($this->disable)($user);

        $this->audit->recordPersonal('two_factor.disabled', $user->id);
    }
}
