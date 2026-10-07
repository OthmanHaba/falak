<?php

namespace Falak\Volumes\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A volume restore drill finished: a failure alerts once per schedule until a drill passes again.
 */
final class VolumeDrillFinished implements Alertable
{
    use Dispatchable;

    public const ALERT_FAILED = 'volumes.drill_failed';

    public const ALERT_PASSED = 'volumes.drill_recovered';

    public function __construct(
        public string $drillId,
        public string $organizationId,
        public ?string $scheduleId,
        public ?string $volumeId,
        public string $volumeName,
        public bool $passed,
        public string $detail,
    ) {}

    public function toAlert(): AlertData
    {
        $dedup = "volumes.drill:{$this->scheduleId}";
        $url = $this->volumeId !== null ? "/volumes/{$this->volumeId}" : null;

        return $this->passed
            ? new AlertData($this->organizationId, self::ALERT_PASSED, Severity::Info, "Restore drills of volume {$this->volumeName} pass again", '',
                $url, $dedup, resolves: true, context: ['drill_id' => $this->drillId])
            : new AlertData($this->organizationId, self::ALERT_FAILED, Severity::Critical, "Restore drill of volume {$this->volumeName} failed", $this->detail,
                $url, $dedup, context: ['drill_id' => $this->drillId]);
    }
}
