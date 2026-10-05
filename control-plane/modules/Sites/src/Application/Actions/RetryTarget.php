<?php

namespace Falak\Sites\Application\Actions;

use Falak\Sites\Application\TargetProvisioner;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\SiteTarget;
use Illuminate\Validation\ValidationException;

final class RetryTarget
{
    public function __construct(private readonly TargetProvisioner $provisioner) {}

    public function __invoke(SiteTarget $target): void
    {
        if ($target->status === TargetStatus::Provisioning) {
            throw ValidationException::withMessages(['target' => 'The server is still being prepared.']);
        }

        $this->provisioner->start($target);
    }
}
