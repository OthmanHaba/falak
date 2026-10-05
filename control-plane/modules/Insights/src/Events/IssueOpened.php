<?php

namespace Falak\Insights\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new issue was created (first occurrence of a fingerprint, a first threshold breach or missed heartbeat).
 */
final class IssueOpened
{
    use Dispatchable;

    /**
     * @param  string  $kind  IssueKind value
     * @param  string  $priority  IssuePriority value
     */
    public function __construct(
        public string $issueId,
        public string $organizationId,
        public ?string $siteId,
        public ?string $serverId,
        public string $kind,
        public string $title,
        public ?string $culprit,
        public string $priority,
        public string $url,
    ) {}
}
