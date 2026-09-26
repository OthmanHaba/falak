<?php

namespace Kiln\Identity\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Domain\Models\User;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

final class RegenerateRecoveryCodes
{
    public function __construct(
        private readonly GenerateNewRecoveryCodes $generate,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(User $user): void
    {
        ($this->generate)($user);

        $this->audit->recordPersonal('two_factor.recovery_codes_regenerated', $user->id);
    }
}
