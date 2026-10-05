<?php

namespace Falak\Processes\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;

/**
 * A program reported by {@see ProgramCrashLooping} is running again.
 */
final class ProgramRecovered implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'processes.recovered';

    public function __construct(
        public string $organizationId,
        public string $serverId,
        public string $serverName,
        public string $siteId,
        public string $program,
        public string $label,
        public string $url,
    ) {}

    public function toAlert(): AlertData
    {
        return new AlertData(
            $this->organizationId,
            self::ALERT_TYPE,
            Severity::Info,
            "{$this->label} is running again on {$this->serverName}",
            'The process stopped crashing.',
            $this->url,
            ProgramCrashLooping::dedupKey($this->serverId, $this->program),
            resolves: true,
            context: ['site_id' => $this->siteId, 'server_id' => $this->serverId, 'program' => $this->program],
        );
    }
}
