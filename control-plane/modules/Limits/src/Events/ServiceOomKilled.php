<?php

namespace Falak\Limits\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The OOM killer killed a process of a service (its own memory limit, or the host out of memory): a container, a
 * process in a site's / worker's / daemon's slice. Rollback after a release went live (step 7) watches for it.
 */
final class ServiceOomKilled implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'limits.oom_killed';

    /**
     * @param  string  $serviceKind  site | compose_service | worker | daemon | database | program
     * @param  string  $serviceId  the site, worker, daemon or database instance id ("<site id>:<service>" for compose)
     * @param  ?int  $memoryLimitMb  the limit it ran with (null: none — the host ran out of memory)
     */
    public function __construct(
        public string $organizationId,
        public string $serverId,
        public string $serverName,
        public string $serviceKind,
        public string $serviceId,
        public ?string $siteId,
        public string $label,
        public int $kills,
        public ?int $memoryLimitMb,
        public string $url,
        public string $at,
    ) {}

    public function toAlert(): AlertData
    {
        $limit = $this->memoryLimitMb !== null
            ? "It hit its memory limit of {$this->memoryLimitMb} MB: raise the limit or find what grows."
            : 'It has no memory limit: the server ran out of memory. Set limits so one service can\'t take the others down.';

        return new AlertData(
            $this->organizationId,
            self::ALERT_TYPE,
            Severity::Critical,
            "{$this->label} was killed for running out of memory on {$this->serverName}",
            ($this->kills > 1 ? "{$this->kills} processes were killed. " : '').$limit,
            $this->url,
            // One alert per service per hour: a service OOM-looping doesn't flood the channels.
            "limits.oom:{$this->serverId}:{$this->serviceKind}:{$this->serviceId}:".substr($this->at, 0, 13),
            context: ['server_id' => $this->serverId, 'service_kind' => $this->serviceKind, 'service_id' => $this->serviceId, 'site_id' => $this->siteId, 'kills' => $this->kills, 'memory_limit_mb' => $this->memoryLimitMb],
        );
    }
}
