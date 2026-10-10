<?php

namespace Falak\Limits\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A service restarted at least config('limits.restart_loop.restarts') times within the window (its restart policy:
 * Docker's, or the supervisor's for programs). Raised once per window; rollback (step 7) and alerts (step 8) use it.
 */
final class ServiceRestartLoop implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'limits.restart_loop';

    /**
     * @param  string  $serviceKind  site | compose_service | worker | daemon | database | program
     */
    public function __construct(
        public string $organizationId,
        public string $serverId,
        public string $serverName,
        public string $serviceKind,
        public string $serviceId,
        public ?string $siteId,
        public string $label,
        public int $restarts,
        public int $windowMinutes,
        public string $url,
        /** When the window started (ISO 8601): one alert per window */
        public string $since,
    ) {}

    public function toAlert(): AlertData
    {
        return new AlertData(
            $this->organizationId,
            self::ALERT_TYPE,
            Severity::Critical,
            "{$this->label} keeps restarting on {$this->serverName}",
            "{$this->restarts} restarts in the last {$this->windowMinutes} minutes. Check its logs: it crashes, or is killed for memory.",
            $this->url,
            "limits.restart_loop:{$this->serverId}:{$this->serviceKind}:{$this->serviceId}:{$this->since}",
            context: ['server_id' => $this->serverId, 'service_kind' => $this->serviceKind, 'service_id' => $this->serviceId, 'site_id' => $this->siteId, 'restarts' => $this->restarts],
        );
    }
}
