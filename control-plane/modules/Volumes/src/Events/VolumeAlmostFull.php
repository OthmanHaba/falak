<?php

namespace Falak\Volumes\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A volume with a size limit crossed {@see THRESHOLD} of it (fired once per crossing, from usage reports).
 */
final class VolumeAlmostFull implements Alertable
{
    use Dispatchable;

    public const ALERT_TYPE = 'volumes.almost_full';

    /** Fraction of the limit in use that alerts. */
    public const THRESHOLD = 0.85;

    /** Fraction it has to drop to (or below) before the alert resolves: no alert/recovery flapping around 85%. */
    public const RESOLVE_BELOW = 0.80;

    public function __construct(
        public string $volumeId,
        public string $organizationId,
        public ?string $serverId,
        public string $volumeName,
        public int $usedBytes,
        public int $limitBytes,
    ) {}

    public function toAlert(): AlertData
    {
        $percent = (int) floor($this->usedBytes / max(1, $this->limitBytes) * 100);

        return new AlertData(
            $this->organizationId,
            self::ALERT_TYPE,
            Severity::Warning,
            "Volume {$this->volumeName} is {$percent}% full",
            'Grow it (Resize) or free some space before it fills up: writes fail once it is full.',
            "/volumes/{$this->volumeId}",
            "volumes.almost_full:{$this->volumeId}",
            context: ['volume_id' => $this->volumeId, 'server_id' => $this->serverId, 'used_bytes' => $this->usedBytes, 'limit_bytes' => $this->limitBytes],
        );
    }
}
