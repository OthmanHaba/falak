<?php

namespace Falak\Deployments\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Servers were rolled back to an earlier release: automatically after a failed deployment ($automatic), through a
 * rollback deployment requested by a user, or automatically after a release went live and its watch tripped
 * ($automatic with a $reason).
 */
final class DeploymentRolledBack implements Alertable
{
    use Dispatchable;

    /**
     * @param  list<string>  $serverIds  servers that now run $toReleaseId
     * @param  ?string  $reason  the watch trigger that rolled a live release back (null otherwise)
     * @param  bool  $migrationsKept  the rolled-back deployment ran database migrations, which stay applied
     */
    public function __construct(
        public string $deploymentId,
        public string $organizationId,
        public string $siteId,
        public string $siteSlug,
        public ?string $toReleaseId,
        public array $serverIds,
        public bool $automatic,
        public ?string $reason = null,
        public bool $migrationsKept = false,
    ) {}

    public function toAlert(): AlertData
    {
        $title = match (true) {
            $this->reason !== null => "{$this->siteSlug} was rolled back after its release went live",
            $this->automatic => "{$this->siteSlug} was rolled back after a failed deployment",
            default => "{$this->siteSlug} was rolled back",
        };

        $body = count($this->serverIds).' server(s) now run release '.($this->toReleaseId ?? 'the previous release').'.';

        if ($this->reason !== null) {
            $body = "{$this->reason}\n{$body}";
        }

        if ($this->migrationsKept) {
            $body .= ' The deployment ran database migrations; they were not reversed.';
        }

        return new AlertData(
            $this->organizationId,
            'deployments.rolled_back',
            $this->automatic ? Severity::Warning : Severity::Info,
            $title,
            $body,
            "/sites/{$this->siteId}/deployments/{$this->deploymentId}",
            context: ['site_id' => $this->siteId, 'deployment_id' => $this->deploymentId, 'release_id' => $this->toReleaseId, 'automatic' => $this->automatic, 'reason' => $this->reason],
        );
    }
}
