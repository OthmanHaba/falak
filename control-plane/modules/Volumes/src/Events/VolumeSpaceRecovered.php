<?php

namespace Falak\Volumes\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A volume that crossed {@see VolumeAlmostFull::THRESHOLD} of its limit is back below it (grown, or space freed):
 * resolves the alert, so the next crossing alerts again.
 */
final class VolumeSpaceRecovered implements Alertable
{
    use Dispatchable;

    public function __construct(
        public string $volumeId,
        public string $organizationId,
        public string $volumeName,
        public int $usedBytes,
        public int $limitBytes,
    ) {}

    public function toAlert(): AlertData
    {
        $percent = (int) floor($this->usedBytes / max(1, $this->limitBytes) * 100);

        return new AlertData(
            $this->organizationId,
            VolumeAlmostFull::ALERT_TYPE,
            Severity::Info,
            "Volume {$this->volumeName} has room again ({$percent}% full)",
            '',
            "/volumes/{$this->volumeId}",
            "volumes.almost_full:{$this->volumeId}",
            resolves: true,
            context: ['volume_id' => $this->volumeId],
        );
    }
}
