<?php

namespace Falak\Deployments\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A live release's watch tripped without rolling back: the site is set to "alert only", or the loop guard held the
 * rollback back ($heldBack says why). A rollback that does happen is announced by {@see DeploymentRolledBack}.
 */
final class ReleaseWatchTriggered implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'deployments.watch_triggered';

    /**
     * @param  string  $trigger  WatchTrigger value
     */
    public function __construct(
        public string $deploymentId,
        public string $organizationId,
        public string $siteId,
        public string $siteSlug,
        public int $number,
        public string $trigger,
        public string $reason,
        public ?string $heldBack,
        public bool $migrations,
        public string $url,
    ) {}

    public function toAlert(): AlertData
    {
        $body = $this->reason;
        $body .= $this->heldBack !== null ? "\nNot rolled back: {$this->heldBack}" : "\nThe site is set to alert only: roll back from the deployment page if needed.";

        if ($this->migrations) {
            $body .= ' The deployment ran database migrations; a rollback would not reverse them.';
        }

        return new AlertData(
            $this->organizationId,
            self::ALERT_TYPE,
            Severity::Critical,
            "Deployment #{$this->number} of {$this->siteSlug} is unhealthy after going live",
            $body,
            $this->url,
            "deployments.watch:{$this->deploymentId}",
            context: ['site_id' => $this->siteId, 'deployment_id' => $this->deploymentId, 'trigger' => $this->trigger, 'held_back' => $this->heldBack],
        );
    }
}
