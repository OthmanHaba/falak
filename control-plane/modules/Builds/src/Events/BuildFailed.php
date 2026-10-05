<?php

namespace Falak\Builds\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A build failed or timed out (cancellations fire BuildCancelled instead).
 */
final class BuildFailed implements Alertable
{
    use Dispatchable;

    /**
     * @param  string  $status  failed | timed_out
     */
    public function __construct(
        public string $buildId,
        public string $organizationId,
        public string $siteId,
        public string $siteSlug,
        public string $status,
        public string $error,
        public ?string $commit,
        public ?string $deploymentId,
    ) {}

    public function toAlert(): AlertData
    {
        $what = $this->status === 'timed_out' ? 'timed out' : 'failed';

        return new AlertData(
            $this->organizationId,
            'builds.failed',
            Severity::Warning,
            "Build of {$this->siteSlug} {$what}",
            $this->error,
            "/builds/{$this->buildId}",
            context: array_filter(['site_id' => $this->siteId, 'build_id' => $this->buildId, 'commit' => $this->commit, 'deployment_id' => $this->deploymentId]),
        );
    }
}
