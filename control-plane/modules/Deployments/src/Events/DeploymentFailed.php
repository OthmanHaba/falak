<?php

namespace Kiln\Deployments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kiln\Alerting\Contracts\Alertable;
use Kiln\Alerting\Contracts\Data\AlertData;
use Kiln\Alerting\Contracts\Severity;

final class DeploymentFailed implements Alertable
{
    use Dispatchable;

    public function __construct(
        public string $deploymentId,
        public string $organizationId,
        public string $siteId,
        public string $siteSlug,
        public int $number,
        public string $trigger,
        public ?string $phase,
        public string $error,
        public ?string $commit,
        public bool $rolledBack,
    ) {}

    public function toAlert(): AlertData
    {
        $commit = $this->commit ? ' ('.substr($this->commit, 0, 7).')' : '';

        return new AlertData(
            $this->organizationId,
            'deployments.failed',
            Severity::Critical,
            "Deployment #{$this->number} of {$this->siteSlug}{$commit} failed",
            $this->error.($this->rolledBack ? ' Activated servers were rolled back.' : ''),
            "/sites/{$this->siteId}/deployments/{$this->deploymentId}",
            "deployments.site:{$this->siteId}",
            context: array_filter([
                'site_id' => $this->siteId,
                'deployment_id' => $this->deploymentId,
                'phase' => $this->phase,
                'commit' => $this->commit,
                'trigger' => $this->trigger,
                'rolled_back' => $this->rolledBack,
            ], fn ($v) => $v !== null),
        );
    }
}
