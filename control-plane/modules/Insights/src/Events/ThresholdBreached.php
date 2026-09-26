<?php

namespace Kiln\Insights\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A configured performance threshold was exceeded (dispatched when the breach opens or
 * reopens its performance issue, not on every evaluation).
 */
final class ThresholdBreached
{
    use Dispatchable;

    /**
     * @param  string  $eventType  request | job | query | command | scheduled_task | outgoing_request
     * @param  string  $metric  p95 | max
     */
    public function __construct(
        public string $organizationId,
        public string $siteId,
        public string $thresholdId,
        public string $issueId,
        public string $eventType,
        public string $name,
        public string $metric,
        public float $valueMs,
        public float $thresholdMs,
        public int $windowMinutes,
        public string $url,
    ) {}
}
