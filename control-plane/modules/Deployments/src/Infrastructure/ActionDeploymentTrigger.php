<?php

namespace Kiln\Deployments\Infrastructure;

use Illuminate\Validation\ValidationException;
use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Contracts\DeploymentTrigger;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Sites\Contracts\SiteDirectory;

final class ActionDeploymentTrigger implements DeploymentTrigger
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly TriggerDeployment $trigger,
    ) {}

    public function deploy(string $siteId, ?string $requestedBy = null): string
    {
        $site = $this->sites->find(strtolower($siteId)) ?? throw ValidationException::withMessages(['site' => 'Unknown site.']);

        return ($this->trigger)($site, Trigger::Manual, requestedBy: $requestedBy)->id;
    }
}
