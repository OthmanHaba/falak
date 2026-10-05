<?php

namespace Falak\Processes\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * proc.status reported a program that keeps exiting (fatal, or in backoff after many restarts).
 */
final class ProgramCrashLooping implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'processes.crash_loop';

    public function __construct(
        public string $organizationId,
        public string $serverId,
        public string $serverName,
        public string $siteId,
        public string $program,
        public string $label,
        public string $state,
        public int $restarts,
        public ?int $lastExitCode,
        public string $url,
    ) {}

    public static function dedupKey(string $serverId, string $program): string
    {
        return "processes.program:{$serverId}:{$program}";
    }

    public function toAlert(): AlertData
    {
        $body = "State {$this->state} after {$this->restarts} restart(s)".($this->lastExitCode !== null ? "; last exit code {$this->lastExitCode}." : '.');

        return new AlertData(
            $this->organizationId,
            self::ALERT_TYPE,
            Severity::Critical,
            "{$this->label} keeps crashing on {$this->serverName}",
            $body,
            $this->url,
            self::dedupKey($this->serverId, $this->program),
            context: ['site_id' => $this->siteId, 'server_id' => $this->serverId, 'program' => $this->program, 'state' => $this->state, 'restarts' => $this->restarts],
        );
    }
}
