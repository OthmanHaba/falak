<?php

namespace Falak\Alerting\Contracts\Data;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;

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
     * @param  string  $detail  in-app only (history, notification center): who, from where, raw errors. Channels to
     *                          third parties (Slack, email, webhooks, …) get $title and $body only
     * @param  string|null  $action  label of the suggested fix at $url (e.g. "Grow volume"); null = the type's registered
     *                               fix ({@see AlertTypes::register()})
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
        public ?string $action = null,
        public string $detail = '',
    ) {}
}
