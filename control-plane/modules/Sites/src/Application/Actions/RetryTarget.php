<?php

namespace Kiln\Sites\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Sites\Application\TargetProvisioner;
use Kiln\Sites\Contracts\TargetStatus;
use Kiln\Sites\Domain\Models\SiteTarget;

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
