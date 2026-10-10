<?php

namespace Falak\Databases\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Point-in-time recovery of an instance needs attention, or is fine again:
 *
 *  - pitr.lag: the oldest unshipped segment is older than databases.pitr.lag_alert_seconds while the instance is up;
 *  - pitr.gap: the log chain broke (binlogs purged before they were spooled, a reset): recovery can't cross it until
 *    the next base backup;
 *  - pitr.spool_full: the spool holds more than databases.pitr.spool_alert_percent of the instance's volume (segments
 *    pile up while they can't be shipped);
 *  - pitr.base_failed: a base backup failed;
 *  - pitr.recovered: resolves the problem of the same kind (lag shipped, gap covered by a new base, spool drained, a
 *    base succeeded).
 *
 * Each problem alerts once per instance until it is resolved.
 */
final class PitrAlert implements Alertable
{
    use Dispatchable;

    public const LAG = 'pitr.lag';

    public const GAP = 'pitr.gap';

    public const SPOOL_FULL = 'pitr.spool_full';

    public const BASE_FAILED = 'pitr.base_failed';

    public const RECOVERED = 'pitr.recovered';

    public const TITLES = [
        self::LAG => 'Point-in-time recovery is lagging',
        self::GAP => 'Gap in the point-in-time recovery timeline',
        self::SPOOL_FULL => 'Point-in-time recovery spool is filling the volume',
        self::BASE_FAILED => 'Point-in-time recovery base backup failed',
    ];

    public function __construct(
        public string $type,
        public string $organizationId,
        public string $instanceId,
        public string $instanceName,
        public string $serverName,
        public string $body = '',
        // The problem a pitr.recovered alert resolves.
        public ?string $resolves = null,
    ) {}

    public static function dedupKey(string $type, string $instanceId): string
    {
        return "databases.{$type}:{$instanceId}";
    }

    public function toAlert(): AlertData
    {
        $problem = $this->resolves ?? $this->type;
        $resolved = $this->resolves !== null;

        return new AlertData(
            $this->organizationId,
            $this->type,
            match (true) {
                $resolved => Severity::Info,
                $this->type === self::LAG => Severity::Warning,
                default => Severity::Critical,
            },
            $resolved
                ? "Point-in-time recovery of {$this->instanceName} on {$this->serverName} is fine again"
                : (self::TITLES[$this->type] ?? 'Point-in-time recovery')." ({$this->instanceName} on {$this->serverName})",
            $this->body,
            "/databases/instances/{$this->instanceId}",
            self::dedupKey($problem, $this->instanceId),
            resolves: $resolved,
            context: ['instance_id' => $this->instanceId, 'instance' => $this->instanceName, 'server' => $this->serverName, 'problem' => $problem],
        );
    }
}
