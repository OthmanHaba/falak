<?php

namespace Falak\Deployments\Infrastructure;

use Illuminate\Validation\ValidationException;
use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Sites\Contracts\SiteDirectory;

final class ActionDeploymentTrigger implements DeploymentTrigger
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly TriggerDeployment $trigger,
    ) {}

    public function deploy(string $siteId, ?string $requestedBy = null, ?string $commit = null, ?string $message = null, ?string $author = null): string
    {
        $site = $this->sites->find(strtolower($siteId)) ?? throw ValidationException::withMessages(['site' => 'Unknown site.']);

        return ($this->trigger)($site, Trigger::Manual, commit: $commit, message: $message, author: $author, requestedBy: $requestedBy)->id;
    }
}
