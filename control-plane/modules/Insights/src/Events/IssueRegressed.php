<?php

namespace Kiln\Insights\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A resolved issue occurred again and was reopened.
 */
final class IssueRegressed
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
