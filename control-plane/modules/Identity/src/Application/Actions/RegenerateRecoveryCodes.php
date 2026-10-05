<?php

namespace Falak\Identity\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Domain\Models\User;
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
