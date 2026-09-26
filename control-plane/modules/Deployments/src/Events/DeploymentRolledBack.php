<?php

namespace Kiln\Deployments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kiln\Alerting\Contracts\Alertable;
use Kiln\Alerting\Contracts\Data\AlertData;
use Kiln\Alerting\Contracts\Severity;

/**
 * Servers were rolled back to an earlier release: automatically after a failed deployment
 * ($automatic) or through a rollback deployment requested by a user.
 */
final class DeploymentRolledBack implements Alertable
{
    use Dispatchable;

    /**
     * @param  list<string>  $serverIds  servers that now run $toReleaseId
     */
    public function __construct(
        public string $deploymentId,
        public string $organizationId,
        public string $siteId,
        public string $siteSlug,
        public ?string $toReleaseId,
        public array $serverIds,
        public bool $automatic,
    ) {}

    public function toAlert(): AlertData
    {
        $title = $this->automatic
            ? "{$this->siteSlug} was rolled back after a failed deployment"
            : "{$this->siteSlug} was rolled back";

        return new AlertData(
            $this->organizationId,
            'deployments.rolled_back',
            $this->automatic ? Severity::Warning : Severity::Info,
            $title,
            count($this->serverIds).' server(s) now run release '.($this->toReleaseId ?? 'the previous release').'.',
            "/sites/{$this->siteId}/deployments/{$this->deploymentId}",
            context: ['site_id' => $this->siteId, 'deployment_id' => $this->deploymentId, 'release_id' => $this->toReleaseId, 'automatic' => $this->automatic],
        );
    }
}
