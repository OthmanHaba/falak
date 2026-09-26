<?php

namespace Kiln\Alerting\Contracts\Data;

use Kiln\Alerting\Contracts\Severity;

/**
 * A notification-worthy occurrence, routed by the organization's alert rules.
 */
final readonly class AlertData
{
    /**
     * @param  string  $type  dotted alert type, e.g. "insights.issue_opened", "fleet.agent_offline"
     * @param  string|null  $dedupKey  alerts sharing a key alert once until a resolving alert clears it
     *                                 (e.g. "insights.issue:<id>"); null = never deduplicated
     * @param  bool  $resolves  this alert clears $dedupKey (recovery); it is only delivered when an
     *                          alert for the same key was delivered before
     * @param  array<string, scalar|null>  $context  extra fields shown in channel messages / webhook payloads
     */
    public function __construct(
        public string $organizationId,
        public string $type,
        public Severity $severity,
        public string $title,
        public string $body = '',
        public ?string $url = null,
        public ?string $dedupKey = null,
        public bool $resolves = false,
        public array $context = [],
    ) {}
}
